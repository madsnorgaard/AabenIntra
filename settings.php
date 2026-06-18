<?php

// Production settings for AabenIntra on VPS2. Bind-mounted into the container at
// web/sites/default/settings.php. All secrets come from the environment (.env);
// nothing sensitive lives in this committed file.

$databases = [];
$databases['default']['default'] = array(
  'database' => getenv('DB_NAME'),
  'username' => getenv('DB_USER'),
  'password' => getenv('DB_PASS'),
  'prefix' => '',
  'host' => getenv('DB_HOST'),
  'port' => getenv('DB_PORT' ?: '3306'),
  'namespace' => 'Drupal\Core\Database\Driver\mysql',
  'driver' => 'mysql',
  // Drupal recommends READ COMMITTED; MariaDB defaults to REPEATABLE-READ.
  // Set per-session rather than restarting the DB server.
  'init_commands' => [
    'isolation_level' => "SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED",
  ],
);

$settings['hash_salt'] = getenv('HASH_SALT');
$settings['update_free_access'] = false;
$settings['container_yamls'][] = $app_root . '/' . $site_path . '/services.yml';

$settings['trusted_host_patterns'][] = getenv('TRUSTED_HOSTS');

$settings['file_scan_ignore_directories'] = [
  'node_modules',
  'bower_components',
];
$settings['entity_update_batch_size'] = 100;
$settings['entity_update_backup'] = true;
$settings['config_sync_directory'] = '../config/sync';

// Private file system, OUTSIDE the docroot per Drupal security guidance.
// Bind-mounted from ./data/private on VPS2 so contents persist across rebuilds.
$settings['file_private_path'] = '/opt/drupal/private';

// Redis cache - enable after adding drupal/redis and `drush en redis`.
// if (extension_loaded('redis')) {
//     $settings['redis.connection']['interface'] = 'PhpRedis';
//     $settings['redis.connection']['host'] = getenv('REDIS_HOST');
//     $settings['redis.connection']['port'] = '6379';
//     $settings['cache']['default'] = 'cache.backend.redis';
//     $settings['cache_prefix'] = 'aabenintra_redis_';
// }

if (file_exists($app_root . '/' . $site_path . '/settings.local.php')) {
    include $app_root . '/' . $site_path . '/settings.local.php';
}

# Force SSL via reverse proxy (Traefik)
$settings['reverse_proxy'] = true;
$settings['reverse_proxy_addresses'] = array(@$_SERVER['REMOTE_ADDR']);

# SMTP configuration from environment
if (getenv('SMTP_PASSWORD')) {
  $config['smtp.settings']['smtp_on'] = TRUE;
  $config['smtp.settings']['smtp_host'] = getenv('SMTP_HOST') ?: 'mail.madsnorgaard.net';
  $config['smtp.settings']['smtp_port'] = getenv('SMTP_PORT') ?: '587';
  $config['smtp.settings']['smtp_protocol'] = getenv('SMTP_PROTOCOL') ?: 'tls';
  $config['smtp.settings']['smtp_username'] = getenv('SMTP_USER') ?: 'mads@madsnorgaard.net';
  $config['smtp.settings']['smtp_password'] = getenv('SMTP_PASSWORD');
  $config['smtp.settings']['smtp_from'] = getenv('SMTP_FROM') ?: 'mads@madsnorgaard.net';
  $config['smtp.settings']['smtp_fromname'] = 'AabenIntra';
  $config['smtp.settings']['smtp_allowhtml'] = TRUE;
  $config['system.mail']['interface']['default'] = 'SMTPMailSystem';
}
