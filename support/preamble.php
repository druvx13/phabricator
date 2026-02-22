<?php

/**
 * Phabricator zero-config bootstrap via .env
 *
 * This preamble is automatically loaded by webroot/index.php on every request
 * (see the phabricator_startup() function). It reads a .env file from the
 * project root and writes conf/local/local.json whenever the .env content
 * changes, enabling deployment without any CLI commands.
 *
 * Deployment workflow:
 *   1. Upload the project to your web host.
 *   2. Copy .env.example to .env and fill in your values.
 *   3. Visit your site — this file does the rest automatically.
 *
 * After the initial setup, run the database installer:
 *   http://yoursite/install.php
 */

$root     = dirname(dirname(__FILE__));
$env_file = $root.'/.env';

// No .env — skip silently (manual bin/config workflow is in use)
if (!file_exists($env_file)) {
  return;
}

// ── Parse the .env file ───────────────────────────────────────────────────

$env     = array();
$lines   = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

foreach ($lines as $line) {
  $line = trim($line);
  if ($line === '' || $line[0] === '#') {
    continue;
  }
  $pos = strpos($line, '=');
  if ($pos === false) {
    continue;
  }
  $key   = trim(substr($line, 0, $pos));
  $value = trim(substr($line, $pos + 1));
  // Strip surrounding quotes (" or ')
  if (strlen($value) >= 2) {
    $first = $value[0];
    $last  = $value[strlen($value) - 1];
    if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
      $value = substr($value, 1, -1);
    }
  }
  $env[$key] = $value;
}

// ── Map .env keys → Phabricator config keys ───────────────────────────────

$mapping = array(
  // Required
  'PHABRICATOR_BASE_URI'   => 'phabricator.base-uri',
  'PHABRICATOR_MYSQL_HOST' => 'mysql.host',
  'PHABRICATOR_MYSQL_PORT' => 'mysql.port',
  'PHABRICATOR_MYSQL_USER' => 'mysql.user',
  'PHABRICATOR_MYSQL_PASS' => 'mysql.pass',
  // Optional
  'PHABRICATOR_NAMESPACE'  => 'storage.default-namespace',
  'PHABRICATOR_TIMEZONE'   => 'phabricator.timezone',
  'PHABRICATOR_FROM_EMAIL' => 'metamta.default-address',
  'PHABRICATOR_SMTP_HOST'  => 'phpmailer.smtp-host',
  'PHABRICATOR_SMTP_PORT'  => 'phpmailer.smtp-port',
  'PHABRICATOR_SMTP_USER'  => 'phpmailer.smtp-user',
  'PHABRICATOR_SMTP_PASS'  => 'phpmailer.smtp-password',
  'PHABRICATOR_SMTP_PROTO' => 'phpmailer.smtp-protocol',
  'PHABRICATOR_REPO_PATH'  => 'repository.default-local-path',
  'PHABRICATOR_FILES_PATH' => 'storage.local-disk.path',
);

$config = array();
foreach ($mapping as $env_key => $phab_key) {
  if (!isset($env[$env_key]) || $env[$env_key] === '') {
    continue;
  }
  $value = $env[$env_key];
  if ($env_key === 'PHABRICATOR_MYSQL_PORT' || $env_key === 'PHABRICATOR_SMTP_PORT') {
    $value = (int) $value;
  }
  $config[$phab_key] = $value;
}

if (empty($config)) {
  return; // .env exists but has no usable values yet
}

// ── Write conf/local/local.json only when content changes ─────────────────

$conf_dir  = $root.'/conf/local';
$conf_file = $conf_dir.'/local.json';
$new_json  = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

// Read existing file (if any) and compare; avoid a write on every request
$existing_json = '';
if (file_exists($conf_file)) {
  $existing_raw = file_get_contents($conf_file);
  if ($existing_raw !== false) {
    $existing_json = $existing_raw;
  }
}

if ($existing_json === $new_json) {
  return; // Already up-to-date — nothing to do
}

// Ensure the directory exists (it may not on a fresh upload)
if (!is_dir($conf_dir)) {
  mkdir($conf_dir, 0755, true);
}

// Write atomically via a temp file so a partial write never corrupts config
$tmp = $conf_file.'.tmp.'.getmypid();
if (file_put_contents($tmp, $new_json) !== false) {
  rename($tmp, $conf_file);
} else {
  @unlink($tmp);
}
