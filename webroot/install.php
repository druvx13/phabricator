<?php
/**
 * Phabricator Web Installer
 *
 * Runs the database schema setup (equivalent to `./bin/storage upgrade`)
 * entirely through the browser — no SSH or CLI access required.
 *
 * HOW TO USE:
 *   1. Upload the project to your web host.
 *   2. Copy .env.example → .env and fill in your database credentials.
 *   3. Visit  http://yoursite/install.php
 *   4. Click "Run Database Setup".
 *   5. Delete or rename this file after setup is complete.
 *
 * SECURITY: This file should be deleted after first use.
 */

// ── Bootstrap ──────────────────────────────────────────────────────────────
define('PHAB_INSTALLER', true);
$root = dirname(dirname(__FILE__));

// ── Helpers ────────────────────────────────────────────────────────────────
function env_parse($path) {
  $env = array();
  if (!file_exists($path)) return $env;
  foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    $pos = strpos($line, '=');
    if ($pos === false) continue;
    $k = trim(substr($line, 0, $pos));
    $v = trim(substr($line, $pos + 1));
    if (strlen($v) >= 2) {
      $f = $v[0]; $l = $v[strlen($v) - 1];
      if (($f === '"' && $l === '"') || ($f === "'" && $l === "'")) {
        $v = substr($v, 1, -1);
      }
    }
    $env[$k] = $v;
  }
  return $env;
}

function render_check(bool $ok, string $label, string $detail = ''): string {
  $icon  = $ok ? '✔' : '✘';
  $class = $ok ? 'pass' : 'fail';
  $d     = $detail ? "<span class='detail'>{$detail}</span>" : '';
  return "<li class='{$class}'><span class='icon'>{$icon}</span> {$label}{$d}</li>";
}

// ── Load .env ──────────────────────────────────────────────────────────────
$env      = env_parse($root.'/.env');
$db_host  = $env['PHABRICATOR_MYSQL_HOST'] ?? 'localhost';
$db_port  = (int)($env['PHABRICATOR_MYSQL_PORT'] ?? 3306);
$db_user  = $env['PHABRICATOR_MYSQL_USER'] ?? '';
$db_pass  = $env['PHABRICATOR_MYSQL_PASS'] ?? '';
$ns       = $env['PHABRICATOR_NAMESPACE'] ?? 'phabricator';
$base_uri = $env['PHABRICATOR_BASE_URI'] ?? '';

// ── Handle POST (run setup) ────────────────────────────────────────────────
$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

  if ($_POST['action'] === 'setup') {
    $logs    = array();
    $success = false;

    try {
      // 1. Connect to MySQL
      $dsn  = "mysql:host={$db_host};port={$db_port};charset=utf8mb4";
      $pdo  = new PDO($dsn, $db_user, $db_pass, array(
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
      ));
      $logs[] = array('ok' => true, 'msg' => "Connected to MySQL at {$db_host}:{$db_port}");

      // 2. Check utf8mb4 support
      $row = $pdo->query("SHOW CHARACTER SET LIKE 'utf8mb4'")->fetch();
      if (!$row) {
        throw new RuntimeException('MySQL does not support utf8mb4. Upgrade to MySQL 5.5+.');
      }
      $logs[] = array('ok' => true, 'msg' => 'utf8mb4 character set is supported');

      // 3. Read and substitute quickstart.sql
      $sql_file = $root.'/resources/sql/quickstart.sql';
      if (!file_exists($sql_file)) {
        throw new RuntimeException('resources/sql/quickstart.sql not found.');
      }
      $sql = file_get_contents($sql_file);

      $substitutions = array(
        '{$NAMESPACE}'        => $ns,
        '{$CHARSET}'          => 'utf8mb4',
        '{$CHARSET_FULLTEXT}' => 'utf8mb4',
        '{$COLLATE_TEXT}'     => 'utf8mb4_bin',
        '{$COLLATE_SORT}'     => 'utf8mb4_unicode_ci',
        '{$COLLATE_FULLTEXT}' => 'utf8mb4_unicode_ci',
      );
      $sql = str_replace(
        array_keys($substitutions),
        array_values($substitutions),
        $sql
      );
      $logs[] = array('ok' => true, 'msg' => "Loaded quickstart.sql (namespace: {$ns})");

      // 4. Split into individual statements and execute.
      // Split on semicolons followed by a newline, then skip blank lines and
      // lines that are purely SQL comments (start with --).
      $statements = preg_split('/;\s*\n/', $sql);
      $count = 0;
      foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') continue;
        // Skip statement fragments that are purely a SQL comment line
        if (preg_match('/^--/', $stmt)) continue;
        $pdo->exec($stmt);
        $count++;
      }
      $logs[] = array('ok' => true, 'msg' => "Executed {$count} SQL statements successfully");

      // 5. Mark setup as done
      $done_file = $root.'/conf/local/.install_done';
      @file_put_contents($done_file, date('c'));

      $success = true;
      $logs[]  = array('ok' => true, 'msg' => 'Database schema installed! You can now delete install.php.');

    } catch (Exception $ex) {
      $logs[] = array('ok' => false, 'msg' => 'Error: '.htmlspecialchars($ex->getMessage()));
    }

    $result = array('success' => $success, 'logs' => $logs);
  }
}

// ── Prerequisite checks ────────────────────────────────────────────────────
$php_ok      = version_compare(PHP_VERSION, '7.2.0', '>=');
$pdo_ok      = extension_loaded('pdo_mysql');
$mbstring_ok = extension_loaded('mbstring');
$curl_ok     = extension_loaded('curl');
$env_ok      = file_exists($root.'/.env');
$env_filled  = $db_user !== '' && $db_pass !== '' && $base_uri !== '';
$conf_dir_ok = is_writable($root.'/conf/local') ||
               (is_dir($root.'/conf/local') && is_writable($root.'/conf/local')) ||
               is_writable($root.'/conf');
$sql_ok      = file_exists($root.'/resources/sql/quickstart.sql');
$already_done = file_exists($root.'/conf/local/.install_done');

$all_prereqs = $php_ok && $pdo_ok && $mbstring_ok && $curl_ok && $env_ok && $env_filled && $sql_ok;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Phabricator – Setup Installer</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
         background: #f4f5f7; color: #172b4d; min-height: 100vh;
         display: flex; align-items: center; justify-content: center; padding: 2rem; }
  .card { background: #fff; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,.12);
          max-width: 680px; width: 100%; padding: 2.5rem; }
  h1 { font-size: 1.6rem; margin-bottom: .25rem; color: #0052cc; }
  .subtitle { color: #6b778c; margin-bottom: 2rem; font-size: .95rem; }
  h2 { font-size: 1.05rem; font-weight: 600; margin: 1.5rem 0 .75rem; color: #253858; }
  ul { list-style: none; margin-bottom: 1rem; }
  li { padding: .4rem 0; display: flex; align-items: flex-start; gap: .6rem;
       font-size: .93rem; border-bottom: 1px solid #f4f5f7; }
  li:last-child { border: none; }
  .icon { font-size: 1rem; flex-shrink: 0; margin-top: .05rem; }
  .pass .icon { color: #36b37e; }
  .fail .icon { color: #de350b; }
  .warn .icon { color: #ff991f; }
  .detail { color: #6b778c; font-size: .83rem; margin-left: .5rem; }
  .btn { display: inline-block; margin-top: 1.5rem; padding: .75rem 1.75rem;
         background: #0052cc; color: #fff; border: none; border-radius: 4px;
         font-size: 1rem; font-weight: 600; cursor: pointer; text-decoration: none; }
  .btn:hover { background: #0065ff; }
  .btn:disabled { background: #97a0af; cursor: not-allowed; }
  .btn-success { background: #36b37e; }
  .btn-success:hover { background: #00875a; }
  .result { margin-top: 1.5rem; padding: 1rem 1.25rem;
            border-radius: 6px; font-size: .9rem; }
  .result.ok  { background: #e3fcef; border: 1px solid #36b37e; }
  .result.err { background: #ffebe6; border: 1px solid #de350b; }
  .log-line { padding: .3rem 0; display: flex; gap: .5rem; }
  .log-line.fail { color: #de350b; }
  .log-line.pass { color: #253858; }
  .log-line .icon { color: inherit; }
  .notice { background: #fffae6; border: 1px solid #ff991f; border-radius: 6px;
            padding: .75rem 1rem; font-size: .88rem; margin-top: 1.25rem; color: #172b4d; }
  code { background: #f4f5f7; padding: .1em .4em; border-radius: 3px; font-size: .88em; }
  .section-sep { border: none; border-top: 2px solid #f4f5f7; margin: 1.75rem 0; }
</style>
</head>
<body>
<div class="card">
  <h1>🔧 Phabricator Setup</h1>
  <p class="subtitle">One-time database installer — delete <code>install.php</code> after use.</p>

  <?php if ($already_done): ?>
  <div class="result ok">
    <strong>✔ Setup already completed.</strong><br>
    The database was set up on <?= htmlspecialchars(file_get_contents($root.'/conf/local/.install_done')) ?>.<br>
    Please <strong>delete this file</strong> and <a href="<?= htmlspecialchars($base_uri ?: '/') ?>">open Phabricator</a>.
  </div>
  <?php endif; ?>

  <h2>① Prerequisites</h2>
  <ul>
    <?= render_check($php_ok,      'PHP '.PHP_VERSION, $php_ok ? '' : 'PHP 7.2+ required') ?>
    <?= render_check($pdo_ok,      'PDO MySQL extension', $pdo_ok ? '' : 'Install php-mysql / php-mysqlnd') ?>
    <?= render_check($mbstring_ok, 'mbstring extension',  $mbstring_ok ? '' : 'Install php-mbstring') ?>
    <?= render_check($curl_ok,     'curl extension',      $curl_ok ? '' : 'Install php-curl') ?>
    <?= render_check($sql_ok,      'resources/sql/quickstart.sql found') ?>
    <?= render_check($conf_dir_ok, 'conf/local/ is writable', $conf_dir_ok ? '' : 'Run: chmod 755 conf/local') ?>
  </ul>

  <h2>② .env Configuration</h2>
  <ul>
    <?= render_check($env_ok,     '.env file exists', $env_ok ? '' : 'Copy .env.example → .env') ?>
    <?= render_check($db_user !== '', 'PHABRICATOR_MYSQL_USER is set',
              $db_user !== '' ? "User: <code>{$db_user}</code>" : 'Set in .env') ?>
    <?= render_check($db_pass !== '', 'PHABRICATOR_MYSQL_PASS is set',
              $db_pass !== '' ? '(hidden)' : 'Set in .env') ?>
    <?= render_check($base_uri !== '', 'PHABRICATOR_BASE_URI is set',
              $base_uri !== '' ? "<code>".htmlspecialchars($base_uri)."</code>" : 'Set in .env') ?>
    <li class="<?= $ns ? 'pass' : 'warn' ?>">
      <span class="icon"><?= $ns ? '✔' : '⚠' ?></span>
      Database namespace
      <span class="detail">Will create databases prefixed <code><?= htmlspecialchars($ns) ?>_*</code></span>
    </li>
  </ul>

  <hr class="section-sep">

  <h2>③ Database Setup</h2>
  <p style="font-size:.9rem;color:#6b778c;margin-bottom:.75rem;">
    This runs the equivalent of <code>./bin/storage upgrade</code>, creating all required
    databases and tables directly from your browser.
  </p>

  <?php if ($result !== null): ?>
    <div class="result <?= $result['success'] ? 'ok' : 'err' ?>">
      <?php foreach ($result['logs'] as $log): ?>
        <div class="log-line <?= $log['ok'] ? 'pass' : 'fail' ?>">
          <span class="icon"><?= $log['ok'] ? '✔' : '✘' ?></span>
          <?= $log['msg'] ?>
        </div>
      <?php endforeach; ?>
      <?php if ($result['success']): ?>
        <a class="btn btn-success" href="<?= htmlspecialchars($base_uri ?: '/') ?>"
           style="margin-top:1rem;">→ Open Phabricator</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (!($result['success'] ?? false)): ?>
    <form method="post">
      <input type="hidden" name="action" value="setup">
      <button class="btn" type="submit" <?= $all_prereqs ? '' : 'disabled' ?>>
        Run Database Setup
      </button>
    </form>
    <?php if (!$all_prereqs): ?>
      <p style="color:#de350b;font-size:.88rem;margin-top:.5rem;">
        Resolve the failing checks above before running setup.
      </p>
    <?php endif; ?>
  <?php endif; ?>

  <div class="notice">
    <strong>⚠ Security reminder:</strong>
    Delete or rename <code>install.php</code> after setup is complete.
    Leaving it accessible allows anyone to reset your database.
  </div>
</div>
</body>
</html>
