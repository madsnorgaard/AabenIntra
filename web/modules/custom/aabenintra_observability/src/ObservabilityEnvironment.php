<?php

declare(strict_types=1);

namespace Drupal\aabenintra_observability;

/**
 * The observability contract with the process environment.
 *
 * Every knob of this module is an environment variable so a tenant container
 * is configured by its .env alone; nothing lives in Drupal config or in the
 * database. This value object is the single place that reads and validates
 * those variables.
 */
final class ObservabilityEnvironment {

  public const SERVICE_NAME = 'aabenintra';

  public const DEFAULT_TRACES_SAMPLE_RATE = 0.05;

  private const LOG_LEVELS = [
    'debug',
    'info',
    'notice',
    'warning',
    'error',
    'critical',
    'alert',
    'emergency',
  ];

  public function __construct(
    public readonly string $environment,
    public readonly ?string $tenant,
    public readonly string $release,
    public readonly string $logLevel,
    public readonly bool $dblog,
    public readonly bool $anonymizeIp,
    public readonly ?string $sentryDsn,
    public readonly string $sentryEnvironment,
    public readonly float $sentryTracesSampleRate,
  ) {}

  /**
   * Builds the contract from the real process environment.
   */
  public static function fromServer(): self {
    $env = [];
    foreach ([
      'APP_ENV',
      'APP_RELEASE',
      'TENANT',
      'TRUSTED_HOSTS',
      'LOG_LEVEL',
      'LOG_DBLOG',
      'LOG_ANONYMIZE_IP',
      'SENTRY_DSN',
      'SENTRY_ENVIRONMENT',
      'SENTRY_TRACES_SAMPLE_RATE',
    ] as $name) {
      $value = getenv($name);
      if ($value === FALSE && isset($_SERVER[$name]) && is_string($_SERVER[$name])) {
        $value = $_SERVER[$name];
      }
      if ($value !== FALSE) {
        $env[$name] = $value;
      }
    }
    return self::fromArray($env);
  }

  /**
   * Builds the contract from an array of environment variables.
   *
   * @param array<string, string> $env
   *   Variable name => raw value.
   */
  public static function fromArray(array $env): self {
    $environment = self::nonEmpty($env, 'APP_ENV') ?? 'production';
    $dsn = self::nonEmpty($env, 'SENTRY_DSN');

    return new self(
      environment: $environment,
      tenant: self::nonEmpty($env, 'TENANT') ?? self::tenantFromTrustedHosts($env['TRUSTED_HOSTS'] ?? ''),
      release: self::nonEmpty($env, 'APP_RELEASE') ?? 'dev',
      logLevel: self::logLevel($env['LOG_LEVEL'] ?? ''),
      dblog: self::flag($env['LOG_DBLOG'] ?? ''),
      anonymizeIp: self::flag($env['LOG_ANONYMIZE_IP'] ?? '1'),
      sentryDsn: $dsn,
      sentryEnvironment: self::nonEmpty($env, 'SENTRY_ENVIRONMENT') ?? $environment,
      sentryTracesSampleRate: self::sampleRate($env['SENTRY_TRACES_SAMPLE_RATE'] ?? ''),
    );
  }

  /**
   * Whether error tracking is switched on (a DSN is present).
   */
  public function sentryEnabled(): bool {
    return $this->sentryDsn !== NULL;
  }

  /**
   * Derives a tenant name from the first TRUSTED_HOSTS pattern.
   *
   * TRUSTED_HOSTS is a regex such as `^intranet\.kommune\.dk$`. When it is a
   * plain escaped hostname the anchors and escapes are stripped; anything
   * with real regex syntax yields NULL rather than a misleading label.
   */
  public static function tenantFromTrustedHosts(string $patterns): ?string {
    $first = trim(explode(',', $patterns)[0]);
    if ($first === '') {
      return NULL;
    }
    $host = preg_replace('/^\^|\$$/', '', $first) ?? '';
    $host = str_replace('\\.', '.', $host);
    if ($host === '' || preg_match('/^[a-z0-9][a-z0-9.-]*$/i', $host) !== 1) {
      return NULL;
    }
    return strtolower($host);
  }

  /**
   * The trimmed value of a variable, or NULL when unset or blank.
   *
   * @param array<string, string> $env
   *   Variable name => raw value.
   * @param string $name
   *   The variable to read.
   */
  private static function nonEmpty(array $env, string $name): ?string {
    $value = trim($env[$name] ?? '');
    return $value === '' ? NULL : $value;
  }

  /**
   * Boolean from the usual truthy spellings (1, true, yes, on).
   */
  private static function flag(string $value): bool {
    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], TRUE);
  }

  /**
   * A known PSR-3 level name, falling back to info.
   */
  private static function logLevel(string $value): string {
    $level = strtolower(trim($value));
    return in_array($level, self::LOG_LEVELS, TRUE) ? $level : 'info';
  }

  /**
   * A sample rate clamped to [0, 1], falling back to the default.
   */
  private static function sampleRate(string $value): float {
    $value = trim($value);
    if ($value === '' || !is_numeric($value)) {
      return self::DEFAULT_TRACES_SAMPLE_RATE;
    }
    return min(1.0, max(0.0, (float) $value));
  }

}
