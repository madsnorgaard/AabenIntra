<?php

declare(strict_types=1);

namespace Drupal\aabenintra_observability\Config;

use Drupal\aabenintra_observability\ObservabilityEnvironment;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;

/**
 * Configures the raven (Sentry) module from the environment.
 *
 * The raven module ships with every capture level switched off, so a DSN
 * alone sends nothing. This override turns on error-and-above capture, fatal
 * error handling and lightly sampled performance tracing, and pins the privacy
 * options (no user data, no IPs, no request bodies, no package list) so a
 * tenant cannot leak personal data to a third party by ticking a checkbox.
 *
 * With SENTRY_DSN empty the override is a no-op and raven stays inert.
 */
final class RavenOverrides implements ConfigFactoryOverrideInterface {

  public const CONFIG_NAME = 'raven.settings';

  /**
   * Channels that are operational noise, not application errors.
   */
  public const IGNORED_CHANNELS = ['page not found', 'access denied'];

  /**
   * {@inheritdoc}
   *
   * @param string[] $names
   *   The config names being loaded.
   *
   * @return array<string, array<string, mixed>>
   *   Overrides keyed by config name.
   */
  public function loadOverrides($names): array {
    if (!in_array(self::CONFIG_NAME, $names, TRUE)) {
      return [];
    }
    $overrides = self::build(ObservabilityEnvironment::fromServer());
    return $overrides === [] ? [] : [self::CONFIG_NAME => $overrides];
  }

  /**
   * The raven.settings values for a given environment (empty when disabled).
   *
   * @return array<string, mixed>
   *   Values for raven.settings.
   */
  public static function build(ObservabilityEnvironment $env): array {
    if (!$env->sentryEnabled()) {
      return [];
    }
    $tracing = $env->sentryTracesSampleRate > 0.0;

    return [
      'client_key' => $env->sentryDsn,
      'environment' => $env->sentryEnvironment,
      'release' => $env->release,
      // Capture: errors and worse. Warnings/notices still arrive as
      // breadcrumbs on the captured event (failed logins included).
      'log_levels' => [
        'emergency' => TRUE,
        'alert' => TRUE,
        'critical' => TRUE,
        'error' => TRUE,
        'warning' => FALSE,
        'notice' => FALSE,
        'info' => FALSE,
        'debug' => FALSE,
      ],
      'fatal_error_handler' => TRUE,
      'drush_error_handler' => TRUE,
      'stack' => TRUE,
      // Never send call arguments; they can contain passwords and CPR numbers.
      'trace' => FALSE,
      'ignored_channels' => self::IGNORED_CHANNELS,
      // Privacy: no PII to the tracker (equivalent of send_default_pii=false).
      'send_user_data' => FALSE,
      'capture_user_ip' => FALSE,
      'send_request_body' => FALSE,
      'modules' => FALSE,
      'auto_session_tracking' => FALSE,
      'send_client_reports' => FALSE,
      'javascript_error_handler' => FALSE,
      'show_report_dialog' => FALSE,
      // Performance: sampled request + database spans surface slow queries.
      'traces_sample_rate' => $env->sentryTracesSampleRate,
      'request_tracing' => $tracing,
      'database_tracing' => $tracing,
      'database_tracing_args' => FALSE,
      'twig_tracing' => FALSE,
      '404_tracing' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheSuffix(): string {
    return 'AabenintraObservabilityRaven';
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata($name): CacheableMetadata {
    return new CacheableMetadata();
  }

  /**
   * {@inheritdoc}
   */
  public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION) {
    return NULL;
  }

}
