<?php
/* ============================================================
 * SCHÜLLER FERRARI · CELLAR ADMIN
 * Self-contained PHP file. Drop into the same folder as
 * index.html and wines.json, then visit /admin.php.
 * On first visit it asks you to set a password.
 * ============================================================ */

date_default_timezone_set('Europe/London');

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', '1');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
  ini_set('session.cookie_secure', '1');
}
ini_set('session.gc_maxlifetime', (string)(60 * 60 * 24 * 30)); // 30 days
session_set_cookie_params(60 * 60 * 24 * 30);
session_start();

const DATA_FILE   = __DIR__ . '/wines.json';
const CONFIG_FILE = __DIR__ . '/cellar-config.php';

/* ---------- Helpers ---------- */

function load_password_hash(): ?string {
  if (!file_exists(CONFIG_FILE)) return null;
  // The config file defines a constant. Use require_once so it doesn't redefine.
  require_once CONFIG_FILE;
  return defined('CELLAR_PASSWORD_HASH') ? CELLAR_PASSWORD_HASH : null;
}

function save_password_hash(string $hash): void {
  $content = "<?php\n// Schüller Ferrari cellar — auto-generated. Do not edit by hand.\n"
           . "define('CELLAR_PASSWORD_HASH', " . var_export($hash, true) . ");\n";
  file_put_contents(CONFIG_FILE, $content, LOCK_EX);
}

function load_wines(): array {
  // CRITICAL: this function must never silently return [] when the file
  // actually exists but can't be parsed. If it did, the next save_wines()
  // would overwrite the corrupt file with an empty array — total data loss.
  if (!file_exists(DATA_FILE)) return [];
  $json = file_get_contents(DATA_FILE);
  if ($json === false) {
    throw new RuntimeException('Could not read wines.json — check file permissions.');
  }
  if (trim($json) === '') return [];
  $data = json_decode($json, true);
  if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
    throw new RuntimeException(
      'wines.json is corrupted (' . json_last_error_msg() . '). '
      . 'Refusing to load so the next save does not overwrite your data. '
      . 'Restore from wines.json.bak in this folder, then refresh.'
    );
  }
  return is_array($data) ? $data : [];
}

function save_wines(array $wines): void {
  // Encode first, fail loudly if it can't be encoded (e.g. invalid UTF-8).
  $payload = json_encode(
    array_values($wines),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
  );
  if ($payload === false) {
    throw new RuntimeException('Could not encode wines as JSON: ' . json_last_error_msg());
  }

  // Atomic write: write to temp, back up the current file, then rename.
  $tmp = DATA_FILE . '.tmp';
  $bak = DATA_FILE . '.bak';
  if (file_put_contents($tmp, $payload, LOCK_EX) === false) {
    throw new RuntimeException('Could not write temp file ' . $tmp);
  }
  // Keep a single rolling backup of the previous good state.
  if (file_exists(DATA_FILE)) {
    @copy(DATA_FILE, $bak);
  }
  if (!rename($tmp, DATA_FILE)) {
    // Some filesystems disallow rename across mount points — fall back to copy.
    if (!copy($tmp, DATA_FILE)) {
      throw new RuntimeException('Could not save wines.json — check folder permissions.');
    }
    @unlink($tmp);
  }
}

function new_id(): string { return 'w' . bin2hex(random_bytes(5)); }

function csrf_token(): string {
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
  return $_SESSION['csrf'];
}

function require_csrf(): void {
  if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    http_response_code(403);
    exit('CSRF token mismatch. Reload and try again.');
  }
}

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function wine_from_form(array $existing = []): array {
  $w = $existing;
  $w['id']       = $existing['id'] ?? new_id();
  $w['type']     = $_POST['type'] ?? 'red';
  $w['name']     = trim($_POST['name'] ?? '');
  $w['producer'] = trim($_POST['producer'] ?? '');
  $w['grapes']   = trim($_POST['grapes'] ?? '');
  $w['size']     = trim($_POST['size'] ?? '75cl');
  $w['year']     = (int)($_POST['year'] ?? date('Y'));

  if (!empty($_POST['tasted_date'])) {
    // Validate the date is real (YYYY-MM-DD and parseable) before storing.
    // Without this, a typo like "2023-13-45" or "abc" would silently move
    // the wine to the Tasted section with garbage data.
    $d = $_POST['tasted_date'];
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false) {
      $w['tasted'] = $d;
      $w['score']  = (float)($_POST['score'] ?? 7);
    } else {
      unset($w['tasted'], $w['score']);
    }
  } else {
    unset($w['tasted'], $w['score']);
  }
  return $w;
}

function format_date_pretty(string $iso): string {
  $months = ['Jan.','Feb.','Mar.','Apr.','May','Jun.','Jul.','Aug.','Sep.','Oct.','Nov.','Dec.'];
  [$y, $m, $d] = array_map('intval', explode('-', $iso));
  $v = $d % 100;
  $suffix = ($v >= 11 && $v <= 13) ? 'th'
          : (['th','st','nd','rd','th','th','th','th','th','th'][$d % 10]);
  return "$d$suffix of {$months[$m - 1]} $y";
}

function format_score(float $s): string {
  $whole = floor($s);
  return ($s - $whole) >= 0.5 ? "{$whole}½" : (string)(int)$whole;
}

function flash_set(string $msg): void { $_SESSION['flash'] = $msg; }
function flash_pop(): ?string { $m = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $m; }

function redirect(string $to = 'admin.php'): void {
  header("Location: $to"); exit;
}

/* ---------- Routing ---------- */

$hash   = load_password_hash();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

/* ---- 1. First-time setup ---- */
if (!$hash) {
  $error = null;
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'setup') {
    require_csrf();
    $pw1 = $_POST['password']         ?? '';
    $pw2 = $_POST['password_confirm'] ?? '';
    if (strlen($pw1) < 8)        $error = 'Password must be at least 8 characters.';
    elseif ($pw1 !== $pw2)       $error = 'Passwords don’t match.';
    else {
      save_password_hash(password_hash($pw1, PASSWORD_DEFAULT));
      session_regenerate_id(true);
      $_SESSION['logged_in'] = true;
      flash_set('Password set. Welcome.');
      redirect();
    }
  }
  render_setup($error);
  exit;
}

/* ---- 2. Login ---- */
if (empty($_SESSION['logged_in'])) {
  $error = null;
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
    require_csrf();
    if (password_verify($_POST['password'] ?? '', $hash)) {
      session_regenerate_id(true);
      $_SESSION['logged_in'] = true;
      redirect();
    } else {
      sleep(1); // basic brute-force slowdown
      $error = 'Wrong password.';
    }
  }
  render_login($error);
  exit;
}

/* ---- 3. Logged-in actions ---- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();
  $wines = load_wines();

  switch ($action) {
    case 'add':
      $w = wine_from_form();
      if ($w['name'] === '') { flash_set('Name required.'); redirect(); }
      $qty = max(1, min(24, (int)($_POST['quantity'] ?? 1)));
      for ($i = 0; $i < $qty; $i++) {
        $copy = $w;
        $copy['id'] = new_id();
        $wines[] = $copy;
      }
      save_wines($wines);
      $section = isset($w['tasted']) ? 'Tasted' : 'Cellar';
      $msg = ($qty === 1)
        ? $w['name'] . ' added to ' . $section . '.'
        : "{$qty} × {$w['name']} added to {$section}.";
      flash_set($msg);
      redirect();

    case 'update':
      $id = $_POST['id'] ?? '';
      $found = false;
      foreach ($wines as $i => $w) {
        if (($w['id'] ?? '') === $id) {
          $updated = wine_from_form($w);
          if ($updated['name'] === '') {
            flash_set('Wine name can’t be empty — no changes saved.');
            redirect();
          }
          $wines[$i] = $updated;
          $found = true;
          break;
        }
      }
      if (!$found) {
        // ID didn't match any row — don't write a no-op save that could
        // clobber a concurrent edit. Just bail with a clear message.
        flash_set('That bottle wasn’t found — nothing was changed.');
        redirect();
      }
      save_wines($wines);
      flash_set('Updated.');
      redirect();

    case 'delete':
      $id = $_POST['id'] ?? '';
      $before = count($wines);
      $wines = array_values(array_filter($wines, fn($w) => ($w['id'] ?? '') !== $id));
      if (count($wines) === $before) {
        // Nothing matched — don't write a no-op save.
        flash_set('That bottle wasn’t found — nothing was deleted.');
        redirect();
      }
      save_wines($wines);
      flash_set('Bottle deleted.');
      redirect();

    case 'change_password':
      $current = $_POST['current_password'] ?? '';
      $new1    = $_POST['new_password']         ?? '';
      $new2    = $_POST['new_password_confirm'] ?? '';
      if (!password_verify($current, $hash))          flash_set('Current password is wrong.');
      elseif (strlen($new1) < 8)                       flash_set('New password must be 8+ characters.');
      elseif ($new1 !== $new2)                         flash_set('New passwords don’t match.');
      else {
        save_password_hash(password_hash($new1, PASSWORD_DEFAULT));
        flash_set('Password changed.');
      }
      redirect();

    case 'logout':
      $_SESSION = [];
      session_destroy();
      redirect();
  }
}

/* ---- 4. Render dashboard (or edit screen) ---- */

$wines  = load_wines();
$editId = $_GET['edit'] ?? null;
$drinkId = $_GET['drink'] ?? null; // "drink" = edit with tasting fields prefilled

$editing = null;
if ($editId || $drinkId) {
  $needle = $editId ?: $drinkId;
  foreach ($wines as $w) if (($w['id'] ?? '') === $needle) { $editing = $w; break; }
  if ($drinkId && $editing && empty($editing['tasted'])) {
    $editing['tasted'] = date('Y-m-d');
    $editing['score']  = 7;
  }
}

render_page($wines, $editing, !!$drinkId);


/* ============================================================
 * RENDERERS
 * ============================================================ */

function head_html(string $title): string {
  return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{$title} · Schüller Ferrari admin</title>
<link rel="icon" href="favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
<link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
<link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
<style>
  :root { --purple: #6633ff; --bg: #F5F5F5; --ink: #141415; --border: rgba(20,20,21,.25); }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body {
    font: 16px/1.4 Helvetica, "Helvetica Neue", Arial, sans-serif;
    background: var(--bg); color: var(--ink);
    -webkit-font-smoothing: antialiased;
    padding: 1.5rem 1rem 4rem;
  }
  .wrap { max-width: 720px; margin: 0 auto; }
  header.bar {
    display: flex; justify-content: space-between; align-items: baseline;
    gap: 1rem; margin-bottom: 1.5rem; padding-bottom: 0.75rem;
    border-bottom: 1px solid var(--border);
  }
  header.bar h1 { font-size: 1rem; font-weight: normal; margin: 0; letter-spacing: -0.02em; color: var(--purple); }
  header.bar a, header.bar button { font-size: 0.85rem; }
  /* Link-styled button: no border/background, inherits font family from body
     and font-size from the surrounding context (e.g. header.bar's 0.85rem),
     so it matches sibling <a> tags. */
  .linkbutton {
    background: none; border: none; padding: 0;
    color: inherit; cursor: pointer;
    text-decoration: underline;
    font-family: inherit;
  }
  h2 { font-size: 0.95rem; font-weight: normal; color: var(--purple); margin: 2rem 0 0.75rem; letter-spacing: -0.02em; display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; }
  h2 .meta { opacity: 0.6; font-size: 0.85rem; font-variant-numeric: tabular-nums; }
  a { color: var(--ink); }
  a:hover { color: var(--purple); }

  .flash {
    background: var(--purple); color: white;
    padding: 0.75rem 1rem; margin-bottom: 1rem;
    font-size: 0.9rem; letter-spacing: -0.01em;
  }

  /* Forms */
  form.card {
    background: white; border: 1px solid var(--border);
    padding: 1rem; margin-bottom: 1.5rem;
  }
  form.card h3 { font-size: 0.9rem; font-weight: normal; margin: 0 0 1rem; letter-spacing: -0.02em; color: var(--purple); }
  .row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 0.75rem; }
  .row.single { grid-template-columns: 1fr; }
  .row.triple { grid-template-columns: 1fr 1fr 1fr; }
  @media (max-width: 480px) { .row, .row.triple { grid-template-columns: 1fr; } }
  label { display: block; font-size: 0.78rem; opacity: 0.7; margin-bottom: 0.2rem; letter-spacing: 0.02em; text-transform: uppercase; }
  input[type=text], input[type=number], input[type=password], input[type=date], select, textarea {
    width: 100%; padding: 0.6rem 0.75rem;
    font: inherit; font-size: 0.95rem;
    background: var(--bg); border: 1px solid var(--border);
    border-radius: 0; -webkit-appearance: none;
  }
  input:focus, select:focus, textarea:focus { outline: 2px solid var(--purple); outline-offset: -1px; }
  button, .btn {
    font: inherit; font-size: 0.9rem;
    padding: 0.55rem 1rem; border: 1px solid var(--ink);
    background: white; color: var(--ink); cursor: pointer;
    text-decoration: none; display: inline-block;
    transition: background 100ms, color 100ms;
  }
  button.primary, .btn.primary { background: var(--purple); border-color: var(--purple); color: white; }
  button.primary:hover, .btn.primary:hover { background: #4a1ad9; }
  button.danger, .btn.danger { color: #b00020; border-color: #b00020; }
  button.danger:hover, .btn.danger:hover { background: #b00020; color: white; }
  button:hover { background: var(--ink); color: white; }
  .actions { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 0.5rem; }

  /* Tasted block (inside form) */
  .tasted-block { background: var(--bg); padding: 0.75rem; margin-top: 0.5rem; border: 1px solid var(--border); }
  .tasted-block .row { margin-bottom: 0; }

  /* Wine list */
  ul.wines { list-style: none; padding: 0; margin: 0; }
  ul.wines li {
    padding: 0.75rem 0;
    border-bottom: 1px solid var(--border);
    display: flex; gap: 0.6rem; align-items: flex-start;
    flex-wrap: wrap;
  }
  ul.wines li .info { flex: 1 1 60%; min-width: 0; }
  ul.wines li .info .top { font-size: 0.95rem; line-height: 1.3; }
  ul.wines li .info .sub { font-size: 0.8rem; opacity: 0.65; margin-top: 0.1rem; }
  ul.wines li .row-actions { display: flex; gap: 0.4rem; flex-wrap: wrap; align-items: flex-start; }
  ul.wines li .row-actions .btn, ul.wines li .row-actions button {
    padding: 0.35rem 0.7rem; font-size: 0.8rem;
  }
  .dot { display: inline-block; width: 0.7em; height: 0.7em; border-radius: 50%; margin-right: 0.4em; vertical-align: middle; }
  .dot.red    { background: #722F37; }
  .dot.pink   { background: #E8A99B; }
  .dot.orange { background: #B07333; }
  .dot.white  { background: #DCC359; border: 1px solid var(--border); }

  /* Login + setup pages */
  .auth-wrap { max-width: 380px; margin: 4rem auto; }
  .auth-wrap h1 { font-size: 1.1rem; font-weight: normal; color: var(--purple); letter-spacing: -0.02em; margin: 0 0 1.5rem; text-align: center; }
  .auth-wrap form.card { margin-bottom: 1rem; }
  .auth-wrap p.note { font-size: 0.8rem; opacity: 0.6; text-align: center; line-height: 1.4; }
  .err { color: #b00020; font-size: 0.85rem; margin-bottom: 0.75rem; }

  /* Inline forms (delete) */
  form.inline { display: inline; margin: 0; padding: 0; background: none; border: none; }

  /* Tools section */
  .tools { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 2.5rem; padding-top: 1rem; border-top: 1px solid var(--border); }
  .tools details { width: 100%; }
  .tools summary { cursor: pointer; font-size: 0.85rem; color: var(--purple); padding: 0.4rem 0; }
  .tools details[open] summary { margin-bottom: 0.75rem; }
</style>
</head>
<body>
<div class="wrap">
HTML;
}

function foot_html(): string {
  return <<<HTML
</div>
<script>
  // Fade out flash messages after a moment so they don't linger
  (function () {
    var flash = document.querySelector('.flash');
    if (!flash) return;
    setTimeout(function () {
      flash.style.transition = 'opacity 400ms ease';
      flash.style.opacity = '0';
      setTimeout(function () { flash.remove(); }, 450);
    }, 3000);
  })();
</script>
</body></html>
HTML;
}

function render_setup(?string $error): void {
  echo head_html('Set up');
  $csrf = h(csrf_token());
  $err  = $error ? "<div class=err>" . h($error) . "</div>" : '';
  echo <<<HTML
  <div class="auth-wrap">
    <h1>SCHÜLLER FERRARI · cellar admin</h1>
    <form class="card" method="post" autocomplete="off">
      <h3>First-time setup</h3>
      {$err}
      <input type="hidden" name="action" value="setup">
      <input type="hidden" name="csrf" value="{$csrf}">
      <div class="row single"><div>
        <label>Choose an admin password (8+ characters)</label>
        <input type="password" name="password" autocomplete="new-password" required minlength="8" autofocus>
      </div></div>
      <div class="row single"><div>
        <label>Confirm password</label>
        <input type="password" name="password_confirm" autocomplete="new-password" required minlength="8">
      </div></div>
      <button class="primary" type="submit">Set password & enter</button>
    </form>
    <p class="note">This creates a file called <code>cellar-config.php</code> in this folder. Keep it private.</p>
  </div>
HTML;
  echo foot_html();
}

function render_login(?string $error): void {
  echo head_html('Sign in');
  $csrf = h(csrf_token());
  $err  = $error ? "<div class=err>" . h($error) . "</div>" : '';
  echo <<<HTML
  <div class="auth-wrap">
    <h1>SCHÜLLER FERRARI · cellar admin</h1>
    <form class="card" method="post" autocomplete="off">
      {$err}
      <input type="hidden" name="action" value="login">
      <input type="hidden" name="csrf" value="{$csrf}">
      <div class="row single"><div>
        <label>Password</label>
        <input type="password" name="password" autocomplete="current-password" required autofocus>
      </div></div>
      <button class="primary" type="submit">Sign in</button>
    </form>
  </div>
HTML;
  echo foot_html();
}

function score_options(float $current): string {
  $opts = '';
  for ($i = 0; $i <= 20; $i++) {
    $val   = $i / 2;
    $sel   = abs($val - $current) < 0.01 ? ' selected' : '';
    $label = format_score($val);
    $opts .= "<option value=\"$val\"$sel>$label / 10</option>";
  }
  return $opts;
}

function render_form_card(?array $editing, bool $isDrink): string {
  $csrf = h(csrf_token());
  $isEdit = !!$editing;
  $w = $editing ?? ['type' => 'red', 'size' => '75cl', 'year' => (int)date('Y')];

  $action = $isEdit ? 'update' : 'add';
  $heading = $isDrink
    ? 'Drink this bottle'
    : ($isEdit ? 'Edit bottle' : 'Add a bottle');

  $idField = $isEdit ? '<input type="hidden" name="id" value="' . h($w['id']) . '">' : '';

  $types = ['red' => 'Red', 'pink' => 'Pink / Rosé', 'orange' => 'Orange', 'white' => 'White'];
  $typeOpts = '';
  foreach ($types as $val => $label) {
    $sel = ($w['type'] === $val) ? ' selected' : '';
    $typeOpts .= "<option value=\"$val\"$sel>$label</option>";
  }

  // Producer and grapes: plain inputs — copy-paste is faster than picking from a list.

  $name     = h($w['name']     ?? '');
  $producer = h($w['producer'] ?? '');
  $grapesV  = h($w['grapes']   ?? '');
  $size     = h($w['size']     ?? '75cl');
  $year     = h((string)($w['year'] ?? date('Y')));
  $taDate   = h($w['tasted']   ?? '');
  $score    = (float)($w['score'] ?? 7);
  $tastedChecked = !empty($w['tasted']) ? 'checked' : '';
  $tastedDisplay = !empty($w['tasted']) ? 'block' : 'none';
  $scoreOpts = score_options($score);

  $cancel = $isEdit ? '<a class="btn" href="admin.php">Cancel</a>' : '';
  $delete = '';
  if ($isEdit) {
    // No nested <form> here — that caused duplicate name="action" inputs in
    // the parsed DOM (HTML doesn't allow nested forms; browsers flatten them)
    // and every submission silently became action=delete. Now it's a plain
    // submit button inside the same form, with its own name=action value=delete
    // that only takes effect when this specific button is clicked.
    // formnovalidate skips required-field checks (we don't need a valid name
    // to delete). The first submit button — the primary one above — is what
    // Enter implicitly triggers, so Enter is always Save, never Delete.
    $delete = <<<HTML
      <button class="danger" type="submit" name="action" value="delete"
              formnovalidate
              onclick="return confirm('Delete this bottle for good?');">Delete</button>
HTML;
  }

  $submitLabel = $isDrink ? 'Save tasting' : ($isEdit ? 'Save changes' : 'Add bottle');

  // Quantity field — visible only when adding a fresh entry (not editing or drinking)
  $qtyField = $isEdit ? '' : <<<HTML
<div>
  <label>Quantity</label>
  <input type="number" name="quantity" value="1" min="1" max="24" step="1">
</div>
HTML;
  $topRowClass = $isEdit ? 'row' : 'row triple';

  return <<<HTML
<form class="card" method="post" id="wineForm">
  <h3>{$heading}</h3>
  <input type="hidden" name="csrf" value="{$csrf}">
  {$idField}

  <div class="{$topRowClass}">
    {$qtyField}
    <div>
      <label>Type</label>
      <select name="type">{$typeOpts}</select>
    </div>
    <div>
      <label>Size</label>
      <input type="text" name="size" value="{$size}" placeholder="75cl">
    </div>
  </div>

  <div class="row single"><div>
    <label>Producer</label>
    <input type="text" name="producer" value="{$producer}" autocomplete="off">
  </div></div>

  <div class="row single"><div>
    <label>Wine name</label>
    <input type="text" name="name" value="{$name}" required autocomplete="off">
  </div></div>

  <div class="row">
    <div>
      <label>Grapes</label>
      <input type="text" name="grapes" value="{$grapesV}" autocomplete="off">
    </div>
    <div>
      <label>Year</label>
      <input type="number" name="year" value="{$year}" min="1900" max="2100">
    </div>
  </div>

  <div class="row single"><div>
    <label style="cursor:pointer">
      <input type="checkbox" id="tastedToggle" {$tastedChecked} onchange="document.getElementById('tastedBlock').style.display = this.checked ? 'block' : 'none'; if(this.checked && !document.querySelector('[name=tasted_date]').value) document.querySelector('[name=tasted_date]').value = new Date().toISOString().slice(0,10);">
      Mark as tasted
    </label>
  </div></div>

  <div class="tasted-block" id="tastedBlock" style="display: {$tastedDisplay}">
    <div class="row">
      <div>
        <label>Date tasted</label>
        <input type="date" name="tasted_date" value="{$taDate}">
      </div>
      <div>
        <label>Score</label>
        <select name="score">{$scoreOpts}</select>
      </div>
    </div>
  </div>

  <div class="actions">
    <button class="primary" type="submit" name="action" value="{$action}">{$submitLabel}</button>
    {$cancel}
    {$delete}
  </div>
</form>
HTML;
}

function render_wine_list(array $wines, string $section): string {
  $csrf = h(csrf_token());
  $isCellar = $section === 'cellar';

  if ($isCellar) {
    $items = array_filter($wines, fn($w) => empty($w['tasted']));
    $typeOrder = ['red' => 0, 'pink' => 1, 'orange' => 2, 'white' => 3];
    usort($items, function($a, $b) use ($typeOrder) {
      $t = ($typeOrder[$a['type']] ?? 9) <=> ($typeOrder[$b['type']] ?? 9);
      if ($t !== 0) return $t;
      $y = ($b['year'] ?? 0) <=> ($a['year'] ?? 0);
      if ($y !== 0) return $y;
      return strcmp($a['name'] ?? '', $b['name'] ?? '');
    });
  } else {
    $items = array_filter($wines, fn($w) => !empty($w['tasted']));
    usort($items, fn($a, $b) => strcmp($b['tasted'], $a['tasted']));
  }

  if (empty($items)) {
    return '<ul class="wines"><li style="opacity:.5">' .
      ($isCellar ? 'Empty. Add a bottle above.' : 'Nothing tasted yet.') . '</li></ul>';
  }

  $typeLabels = ['red' => 'Red wine', 'pink' => 'Rosé wine', 'orange' => 'Orange wine', 'white' => 'White wine'];
  $html = '<ul class="wines">';
  foreach ($items as $w) {
    $id    = h($w['id']);
    $type  = h($w['type']);
    $typeLabel = $typeLabels[$w['type']] ?? 'Wine';
    $name  = h($w['name']);
    $prod  = h($w['producer'] ?? '');
    $grap  = h($w['grapes'] ?? '');
    $size  = h($w['size'] ?? '');
    $year  = h((string)($w['year'] ?? ''));

    $tastedInfo = '';
    if (!empty($w['tasted'])) {
      $tastedInfo = ' · Tasted ' . h(format_date_pretty($w['tasted']))
                  . ' · ' . h(format_score((float)$w['score'])) . '/10';
    }

    $primaryBtn = $isCellar
      ? "<a class=\"btn primary\" href=\"admin.php?drink={$id}\">🍷 Drink</a>"
      : "<a class=\"btn\" href=\"admin.php?edit={$id}\">Edit</a>";

    $editLink = $isCellar
      ? "<a class=\"btn\" href=\"admin.php?edit={$id}\">Edit</a>"
      : '';

    $deleteForm = <<<HTML
<form class="inline" method="post" onsubmit="return confirm('Delete this bottle?');">
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="csrf" value="{$csrf}">
  <input type="hidden" name="id" value="{$id}">
  <button class="danger" type="submit">×</button>
</form>
HTML;

    $html .= <<<HTML
<li>
  <div class="info">
    <div class="top"><span class="dot {$type}" role="img" aria-label="{$typeLabel}"></span>{$name} · <em>{$year}</em></div>
    <div class="sub">{$prod} · {$grap} · {$size}{$tastedInfo}</div>
  </div>
  <div class="row-actions">{$primaryBtn}{$editLink}{$deleteForm}</div>
</li>
HTML;
  }
  return $html . '</ul>';
}

function render_page(array $wines, ?array $editing, bool $isDrink): void {
  $csrf  = h(csrf_token());
  $flash = flash_pop();
  $flashHtml = $flash ? '<div class="flash">' . h($flash) . '</div>' : '';

  $cellarCount = count(array_filter($wines, fn($w) => empty($w['tasted'])));
  $tastedItems = array_filter($wines, fn($w) => !empty($w['tasted']));
  $tastedCount = count($tastedItems);
  $tastedMeta  = '';
  if ($tastedCount) {
    $avg = array_sum(array_map(fn($w) => (float)$w['score'], $tastedItems)) / $tastedCount;
    $avg = round($avg * 2) / 2;
    $tastedMeta = " · avg " . format_score($avg) . "/10";
  }

  $formCard = render_form_card($editing, $isDrink);
  $cellarHtml = render_wine_list($wines, 'cellar');
  $tastedHtml = render_wine_list($wines, 'tasted');

  $cellarPlural = $cellarCount === 1 ? '' : 's';
  $tastedPlural = $tastedCount === 1 ? '' : 's';

  echo head_html('Cellar admin');
  echo <<<HTML
  <header class="bar">
    <h1>SCHÜLLER FERRARI · admin</h1>
    <span>
      <a href="index.html" target="_blank">View site ↗</a>
      &nbsp;·&nbsp;
      <form class="inline" method="post" style="display:inline">
        <input type="hidden" name="action" value="logout">
        <input type="hidden" name="csrf" value="{$csrf}">
        <button type="submit" class="linkbutton">Sign out</button>
      </form>
    </span>
  </header>

  {$flashHtml}
  {$formCard}

  <h2><span>Cellar</span><span class="meta">{$cellarCount} bottle{$cellarPlural}</span></h2>
  {$cellarHtml}

  <h2><span>Tasted</span><span class="meta">{$tastedCount} bottle{$tastedPlural}{$tastedMeta}</span></h2>
  {$tastedHtml}

  <div class="tools">
    <details>
      <summary>Tools</summary>
      <p style="margin:0 0 0.75rem;font-size:0.85rem;opacity:0.7">Back up or change password. The "View site" link above opens the public page.</p>
      <p style="margin:0 0 1rem">
        <a class="btn" href="wines.json" download>Download backup (wines.json)</a>
      </p>

      <form class="card" method="post" style="margin-top:0;padding:0.75rem 1rem;">
        <h3 style="margin-bottom:0.75rem">Change password</h3>
        <input type="hidden" name="action" value="change_password">
        <input type="hidden" name="csrf" value="{$csrf}">
        <div class="row triple">
          <div>
            <label>Current</label>
            <input type="password" name="current_password" required autocomplete="current-password">
          </div>
          <div>
            <label>New (8+)</label>
            <input type="password" name="new_password" required minlength="8" autocomplete="new-password">
          </div>
          <div>
            <label>Confirm new</label>
            <input type="password" name="new_password_confirm" required minlength="8" autocomplete="new-password">
          </div>
        </div>
        <div class="actions"><button type="submit">Update password</button></div>
      </form>

      <form method="post" style="margin-top:0.75rem">
        <input type="hidden" name="action" value="logout">
        <input type="hidden" name="csrf" value="{$csrf}">
        <button type="submit">Sign out of this device</button>
      </form>
    </details>
  </div>
HTML;
  echo foot_html();
}
