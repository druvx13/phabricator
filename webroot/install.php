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
 *   3. On shared hosting: create all required databases in cPanel first
 *      (see the "Required Databases" section on this page).
 *   4. Visit  http://yoursite/install.php
 *   5. Click "Run Database Setup".
 *   6. Delete or rename this file after setup is complete.
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

/**
 * Extract all unique database suffixes from quickstart.sql by scanning for
 * CREATE DATABASE statements containing the {$NAMESPACE}_ placeholder.
 */
function get_db_suffixes(string $sql_path): array {
  if (!file_exists($sql_path)) return array();
  $content = file_get_contents($sql_path);
  preg_match_all('/CREATE DATABASE[^`]*`\{\$NAMESPACE\}_([^`]+)`/i', $content, $m);
  $suffixes = array_unique($m[1]);
  sort($suffixes);
  return $suffixes;
}

// ── Load .env ──────────────────────────────────────────────────────────────
$env      = env_parse($root.'/.env');
$db_host  = $env['PHABRICATOR_MYSQL_HOST'] ?? 'localhost';
$db_port  = (int)($env['PHABRICATOR_MYSQL_PORT'] ?? 3306);
$db_user  = $env['PHABRICATOR_MYSQL_USER'] ?? '';
$db_pass  = $env['PHABRICATOR_MYSQL_PASS'] ?? '';
$ns       = $env['PHABRICATOR_NAMESPACE'] ?? 'phabricator';
$base_uri = $env['PHABRICATOR_BASE_URI'] ?? '';

// ── Required database names ────────────────────────────────────────────────
// If the user has explicitly listed their pre-created databases in .env via
// PHABRICATOR_DB_LIST (comma-separated), use that list directly.
// Otherwise, derive names automatically from PHABRICATOR_NAMESPACE + the known
// set of application suffixes extracted from quickstart.sql.
$sql_path    = $root.'/resources/sql/quickstart.sql';
$db_suffixes = get_db_suffixes($sql_path);

$db_list_raw = trim($env['PHABRICATOR_DB_LIST'] ?? '');
if ($db_list_raw !== '') {
  // Parse comma-separated or newline-separated list, strip whitespace and quotes
  $required_dbs    = array_values(array_filter(
    array_map('trim', preg_split('/[\s,]+/', $db_list_raw))
  ));
  $explicit_db_list = true;   // user explicitly defined the list
} else {
  $required_dbs    = array_map(function($s) use ($ns) { return "{$ns}_{$s}"; }, $db_suffixes);
  $explicit_db_list = false;  // auto-generated from namespace
}

// ── Handle POST (run setup) ────────────────────────────────────────────────
$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

  if ($_POST['action'] === 'setup') {
    $logs        = array();
    $success     = false;
    $missing_dbs = array();    // databases not yet created on shared hosting
    $shared_mode = false;      // true when provider denies CREATE DATABASE

    try {
      // 1. Connect to MySQL (no default database)
      $dsn = "mysql:host={$db_host};port={$db_port};charset=utf8mb4";
      $pdo = new PDO($dsn, $db_user, $db_pass, array(
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
      if (!file_exists($sql_path)) {
        throw new RuntimeException('resources/sql/quickstart.sql not found.');
      }
      $sql = file_get_contents($sql_path);
      $substitutions = array(
        '{$NAMESPACE}'        => $ns,
        '{$CHARSET}'          => 'utf8mb4',
        '{$CHARSET_FULLTEXT}' => 'utf8mb4',
        '{$COLLATE_TEXT}'     => 'utf8mb4_bin',
        '{$COLLATE_SORT}'     => 'utf8mb4_unicode_ci',
        '{$COLLATE_FULLTEXT}' => 'utf8mb4_unicode_ci',
      );
      $sql = str_replace(array_keys($substitutions), array_values($substitutions), $sql);
      $logs[] = array('ok' => true, 'msg' => "Loaded quickstart.sql (namespace: {$ns})");

      // 4. Split into individual statements
      $statements = preg_split('/;\s*\n/', $sql);

      // ── Phase 1: Process CREATE DATABASE statements ────────────────────
      // On a VPS/local install the user has full privileges and CREATE DATABASE
      // succeeds immediately.
      // On shared hosting, providers deny CREATE DATABASE (SQLSTATE 42000 /
      // error 1044). In that case we check information_schema to see whether
      // the database was pre-created manually in the hosting control panel.
      // Any databases that are neither creatable nor pre-existing are collected
      // in $missing_dbs and reported to the user.
      //
      // If PHABRICATOR_DB_LIST is set in .env the user has explicitly declared
      // their databases; we skip CREATE DATABASE altogether and just verify each
      // listed database exists and is accessible.

      if ($explicit_db_list) {
        // Explicit mode: user listed databases in .env — verify each exists
        foreach ($required_dbs as $db_name) {
          $chk = $pdo->prepare(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?'
          );
          $chk->execute(array($db_name));
          if (!$chk->fetch()) {
            $missing_dbs[] = $db_name;
          }
        }
        if (empty($missing_dbs)) {
          $shared_mode = true; // all pre-exist — skip CREATE DATABASE in Phase 2
          $logs[] = array(
            'ok'  => true,
            'msg' => 'Explicit DB list mode: all '.count($required_dbs).' databases '
                   . 'found — running CREATE TABLE only.',
          );
        }
      } else {
        // Auto-detect mode: try CREATE DATABASE; handle 1044 from shared hosts
        foreach ($statements as $stmt) {
          $stmt = trim($stmt);
          if ($stmt === '' || preg_match('/^--/', $stmt)) continue;
          if (!preg_match('/^\s*CREATE\s+DATABASE\b/i', $stmt)) continue;

          try {
            $pdo->exec($stmt);
          } catch (PDOException $e) {
            $code = $e->getCode();
            $msg  = $e->getMessage();
            // 42000 / 1044 = shared hosting provider denies CREATE DATABASE
            if ($code === '42000' && strpos($msg, '1044') !== false) {
              $shared_mode = true;
              // Extract the database name from the statement (`name`)
              if (preg_match('/`([^`]+)`/', $stmt, $m)) {
                $db_name = $m[1];
                // Check whether the database was pre-created in cPanel
                $chk = $pdo->prepare(
                  'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?'
                );
                $chk->execute(array($db_name));
                if (!$chk->fetch()) {
                  $missing_dbs[] = $db_name;
                }
              }
            } else {
              throw $e; // unexpected error — re-throw
            }
          }
        }
      }

      // If any required databases are missing, stop here and tell the user
      // exactly which ones to create in cPanel before re-running setup.
      if (!empty($missing_dbs)) {
        $mc = count($missing_dbs);
        $tc = count($required_dbs);
        $logs[] = array(
          'ok'   => false,
          'msg'  => "Shared hosting mode: {$mc} of {$tc} required databases have not been "
                  . "created yet. Please create them in your hosting control panel (cPanel), "
                  . "then click \"Run Database Setup\" again.",
          'dbs'  => $missing_dbs,
        );
        $result = array('success' => false, 'logs' => $logs, 'missing_dbs' => $missing_dbs);
        // Skip Phase 2 — no point running CREATE TABLE on non-existent databases
        return; // exits the POST handler, falls through to the HTML output below
      }

      if ($shared_mode) {
        $logs[] = array(
          'ok'  => true,
          'msg' => 'Shared hosting mode: all '.count($required_dbs).' databases are '
                 . 'pre-created — skipping CREATE DATABASE, running CREATE TABLE only.',
        );
      }

      // ── Phase 2: Execute everything except CREATE DATABASE ─────────────
      // CREATE DATABASEs were already handled in Phase 1.
      // All remaining statements (USE, SET NAMES, SET character_set_client,
      // CREATE TABLE, CREATE INDEX, INSERT …) are executed here.

      $count = 0;
      foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '' || preg_match('/^--/', $stmt)) continue;
        // Skip CREATE DATABASE — already handled above
        if (preg_match('/^\s*CREATE\s+DATABASE\b/i', $stmt)) continue;
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
$php_ok       = version_compare(PHP_VERSION, '7.2.0', '>=');
$pdo_ok       = extension_loaded('pdo_mysql');
$mbstring_ok  = extension_loaded('mbstring');
$curl_ok      = extension_loaded('curl');
$env_ok       = file_exists($root.'/.env');
$env_filled   = $db_user !== '' && $db_pass !== '' && $base_uri !== '';
$conf_dir_ok  = is_writable($root.'/conf/local') ||
                (is_dir($root.'/conf/local') && is_writable($root.'/conf/local')) ||
                is_writable($root.'/conf');
$sql_ok       = file_exists($sql_path);
$already_done = file_exists($root.'/conf/local/.install_done');

$all_prereqs  = $php_ok && $pdo_ok && $mbstring_ok && $curl_ok
             && $env_ok && $env_filled && $sql_ok;
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
          max-width: 720px; width: 100%; padding: 2.5rem; }
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
  .log-line { padding: .3rem 0; display: flex; gap: .5rem; align-items: flex-start; }
  .log-line.fail { color: #de350b; }
  .log-line.pass { color: #253858; }
  .log-line .icon { color: inherit; flex-shrink: 0; }
  .notice { background: #fffae6; border: 1px solid #ff991f; border-radius: 6px;
            padding: .75rem 1rem; font-size: .88rem; margin-top: 1.25rem; color: #172b4d; }
  .info-box { background: #deebff; border: 1px solid #4c9aff; border-radius: 6px;
              padding: .85rem 1rem; font-size: .88rem; margin-bottom: .75rem; color: #172b4d; }
  code { background: #f4f5f7; padding: .1em .4em; border-radius: 3px; font-size: .88em; }
  .section-sep { border: none; border-top: 2px solid #f4f5f7; margin: 1.75rem 0; }
  /* Required databases list */
  details { margin-bottom: 1rem; }
  summary { cursor: pointer; font-weight: 600; font-size: .93rem; color: #0052cc;
            padding: .4rem 0; user-select: none; }
  summary:hover { color: #0065ff; }
  .db-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
             gap: .3rem .75rem; margin-top: .75rem; }
  .db-name { font-family: monospace; font-size: .82rem; padding: .2rem .4rem;
             background: #f4f5f7; border-radius: 3px; color: #172b4d; }
  .db-name.missing { background: #ffebe6; color: #de350b; font-weight: 600; }
  /* Missing databases error callout */
  .missing-callout { background: #ffebe6; border: 1px solid #de350b; border-radius: 6px;
                     padding: 1rem 1.25rem; margin-top: 1rem; }
  .missing-callout h3 { font-size: 1rem; color: #de350b; margin-bottom: .5rem; }
  .missing-callout p  { font-size: .88rem; margin-bottom: .75rem; }
  .copy-btn { font-size: .78rem; padding: .2rem .6rem; background: #0052cc; color: #fff;
              border: none; border-radius: 3px; cursor: pointer; margin-left: .5rem; }
  .copy-btn:hover { background: #0065ff; }
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
    <?= render_check($conf_dir_ok, 'conf/local/ is writable', $conf_dir_ok ? '' : 'chmod 755 conf/local') ?>
  </ul>

  <h2>② .env Configuration</h2>
  <ul>
    <?= render_check($env_ok, '.env file exists', $env_ok ? '' : 'Copy .env.example → .env') ?>
    <?= render_check($db_user !== '', 'PHABRICATOR_MYSQL_USER is set',
          $db_user !== '' ? "User: <code>{$db_user}</code>" : 'Set in .env') ?>
    <?= render_check($db_pass !== '', 'PHABRICATOR_MYSQL_PASS is set',
          $db_pass !== '' ? '(hidden)' : 'Set in .env') ?>
    <?= render_check($base_uri !== '', 'PHABRICATOR_BASE_URI is set',
          $base_uri !== '' ? "<code>".htmlspecialchars($base_uri)."</code>" : 'Set in .env') ?>
    <li class="<?= $ns ? 'pass' : 'warn' ?>">
      <span class="icon"><?= $ns ? '✔' : '⚠' ?></span>
      Database namespace: <code style="margin-left:.3rem"><?= htmlspecialchars($ns) ?></code>
      <span class="detail">databases will be named <code><?= htmlspecialchars($ns) ?>_almanac</code>, <code><?= htmlspecialchars($ns) ?>_user</code>, …</span>
    </li>
    <?= render_check(
      $explicit_db_list,
      'PHABRICATOR_DB_LIST is set',
      $explicit_db_list
        ? count($required_dbs).' databases explicitly defined'
        : 'Not set — auto-generated from namespace (required on shared hosting; see ③ below)'
    ) ?>
  </ul>

  <?php if (!empty($required_dbs)): ?>
  <hr class="section-sep">

  <h2>③ Required Databases <span style="font-weight:400;color:#6b778c">(<?= count($required_dbs) ?> total)</span></h2>

  <div class="info-box">
    <strong>📋 Shared hosting users (InfinityFree, cPanel, etc.):</strong><br>
    Your hosting provider does not allow databases to be created automatically.
    You must <strong>create all <?= count($required_dbs) ?> databases listed below
    manually</strong> in your hosting control panel (cPanel → MySQL Databases)
    <em>before</em> clicking "Run Database Setup".<br><br>
    Each database name must start with your account username prefix
    (e.g. <code>nhyfe_40946451_</code>). Set <code>PHABRICATOR_NAMESPACE</code>
    in your <code>.env</code> to that prefix (e.g. <code>nhyfe_40946451</code>).<br><br>
    After creating all databases in cPanel, <strong>assign your MySQL user to each
    one</strong> with All Privileges. Then use the
    <strong>"📋 Copy PHABRICATOR_DB_LIST for .env"</strong> button below to copy
    the complete list and paste it into your <code>.env</code> file.
  </div>

  <?php
  // Determine which databases are missing (only if we have a setup result)
  $result_missing = $result['missing_dbs'] ?? array();
  ?>

  <details <?= !empty($result_missing) ? 'open' : '' ?>>
    <summary>
      <?php if (!empty($result_missing)): ?>
        ⚠ <?= count($result_missing) ?> missing / <?= count($required_dbs) ?> required databases
      <?php else: ?>
        <?= $explicit_db_list ? '✔ Showing' : 'View' ?> all <?= count($required_dbs) ?> required database names
      <?php endif; ?>
    </summary>
    <div class="db-grid" style="margin-bottom:.75rem;margin-top:.5rem">
      <?php foreach ($required_dbs as $db): ?>
        <span class="db-name <?= in_array($db, $result_missing) ? 'missing' : '' ?>">
          <?= htmlspecialchars($db) ?>
        </span>
      <?php endforeach; ?>
    </div>
    <button class="copy-btn" onclick="copyEnvLine()">📋 Copy PHABRICATOR_DB_LIST for .env</button>
    <span id="copy-ok" style="font-size:.8rem;color:#36b37e;display:none;margin-left:.5rem">Copied! Paste into your .env file.</span>
  </details>
  <?php endif; ?>

  <hr class="section-sep">

  <h2><?= !empty($required_dbs) ? '④' : '③' ?> Database Setup</h2>
  <p style="font-size:.9rem;color:#6b778c;margin-bottom:.75rem;">
    Runs the equivalent of <code>./bin/storage upgrade</code>.
    On shared hosting: creates tables inside your pre-created databases.
    On a VPS: creates the databases and tables automatically.
  </p>

  <?php if ($result !== null): ?>
    <div class="result <?= $result['success'] ? 'ok' : 'err' ?>">
      <?php foreach ($result['logs'] as $log): ?>
        <div class="log-line <?= $log['ok'] ? 'pass' : 'fail' ?>">
          <span class="icon"><?= $log['ok'] ? '✔' : '✘' ?></span>
          <span><?= $log['msg'] ?></span>
        </div>
      <?php endforeach; ?>

      <?php if (!empty($result_missing)): ?>
      <div class="missing-callout">
        <h3>⚠ <?= count($result_missing) ?> database<?= count($result_missing) > 1 ? 's' : '' ?> not found</h3>
        <p>
          Go to your hosting control panel → <strong>MySQL Databases</strong> and create
          each of the following databases, then assign your MySQL user
          (<code><?= htmlspecialchars($db_user) ?></code>) with <strong>All Privileges</strong>.
          Once done, click <strong>Run Database Setup</strong> again.
        </p>
        <div class="db-grid">
          <?php foreach ($result_missing as $db): ?>
            <span class="db-name missing"><?= htmlspecialchars($db) ?></span>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

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

<script>
function copyEnvLine() {
  var names = <?= json_encode($required_dbs) ?>;
  var line  = 'PHABRICATOR_DB_LIST=' + names.join(',');
  navigator.clipboard.writeText(line).then(function() {
    var el = document.getElementById('copy-ok');
    el.style.display = 'inline';
    setTimeout(function() { el.style.display = 'none'; }, 3000);
  });
}
</script>
</body>
</html>
