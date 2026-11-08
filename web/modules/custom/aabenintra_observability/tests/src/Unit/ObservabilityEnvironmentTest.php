<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenintra_observability\Unit;

use Drupal\aabenintra_observability\Config\RavenOverrides;
use Drupal\aabenintra_observability\ObservabilityEnvironment;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the environment contract and the raven overrides built from it.
 */
#[CoversClass(\Drupal\aabenintra_observability\ObservabilityEnvironment::class)]
#[Group('aabenintra_observability')]
final class ObservabilityEnvironmentTest extends UnitTestCase {

  /**
   * Defaults.
   */
  public function testDefaults(): void {
    $env = ObservabilityEnvironment::fromArray([]);
    self::assertSame('production', $env->environment);
    self::assertNull($env->tenant);
    self::assertSame('dev', $env->release);
    self::assertSame('info', $env->logLevel);
    self::assertFalse($env->dblog);
    self::assertTrue($env->anonymizeIp);
    self::assertFalse($env->sentryEnabled());
    self::assertSame('production', $env->sentryEnvironment);
    self::assertSame(0.05, $env->sentryTracesSampleRate);
  }

  /**
   * Explicit values and validation.
   */
  public function testExplicitValuesAndValidation(): void {
    $env = ObservabilityEnvironment::fromArray([
      'APP_ENV' => 'staging',
      'APP_RELEASE' => ' 1a2b3c ',
      'TENANT' => 'kommune-a',
      'TRUSTED_HOSTS' => 'ignored\.example',
      'LOG_LEVEL' => 'WARNING',
      'LOG_DBLOG' => 'true',
      'LOG_ANONYMIZE_IP' => '0',
      'SENTRY_DSN' => 'https://key@glitchtip.example.dk/3',
      'SENTRY_TRACES_SAMPLE_RATE' => '1.7',
    ]);
    self::assertSame('staging', $env->environment);
    self::assertSame('1a2b3c', $env->release);
    self::assertSame('kommune-a', $env->tenant, 'TENANT wins over TRUSTED_HOSTS.');
    self::assertSame('warning', $env->logLevel);
    self::assertTrue($env->dblog);
    self::assertFalse($env->anonymizeIp);
    self::assertTrue($env->sentryEnabled());
    self::assertSame('staging', $env->sentryEnvironment, 'Falls back to APP_ENV.');
    self::assertSame(1.0, $env->sentryTracesSampleRate, 'Clamped to [0, 1].');

    $env = ObservabilityEnvironment::fromArray(['LOG_LEVEL' => 'verbose', 'SENTRY_TRACES_SAMPLE_RATE' => 'lots']);
    self::assertSame('info', $env->logLevel, 'Unknown level falls back to info.');
    self::assertSame(0.05, $env->sentryTracesSampleRate, 'Non-numeric rate falls back to the default.');
  }

  /**
   * Tenant from trusted hosts.
   */
  #[DataProvider('trustedHostsProvider')]
  public function testTenantFromTrustedHosts(string $pattern, ?string $expected): void {
    self::assertSame($expected, ObservabilityEnvironment::tenantFromTrustedHosts($pattern));
  }

  /**
   * TRUSTED_HOSTS patterns and the tenant label each should yield.
   *
   * @return array<string, array{string, string|null}>
   *   Pattern and expected tenant.
   */
  public static function trustedHostsProvider(): array {
    return [
      'escaped host' => ['aabenintra\.fenixnordic\.solutions', 'aabenintra.fenixnordic.solutions'],
      'anchored' => ['^Intranet\.Kommune\.dk$', 'intranet.kommune.dk'],
      'first of list' => ['^a\.dk$, ^b\.dk$', 'a.dk'],
      'real regex' => ['^.+\.kommune\.dk$', NULL],
      'empty' => ['', NULL],
    ];
  }

  /**
   * Raven overrides are empty without dsn.
   */
  public function testRavenOverridesAreEmptyWithoutDsn(): void {
    self::assertSame([], RavenOverrides::build(ObservabilityEnvironment::fromArray([])));
  }

  /**
   * Raven overrides with dsn.
   */
  public function testRavenOverridesWithDsn(): void {
    $overrides = RavenOverrides::build(ObservabilityEnvironment::fromArray([
      'SENTRY_DSN' => 'https://key@sentry.example/1',
      'SENTRY_ENVIRONMENT' => 'prod-kommune-a',
      'APP_RELEASE' => 'deadbeef',
      'SENTRY_TRACES_SAMPLE_RATE' => '0',
    ]));
    self::assertSame('https://key@sentry.example/1', $overrides['client_key']);
    self::assertSame('prod-kommune-a', $overrides['environment']);
    self::assertSame('deadbeef', $overrides['release']);
    self::assertTrue($overrides['log_levels']['error']);
    self::assertFalse($overrides['log_levels']['warning']);
    self::assertSame(['page not found', 'access denied'], $overrides['ignored_channels']);
    foreach (['send_user_data', 'capture_user_ip', 'send_request_body', 'modules', 'trace', 'database_tracing_args'] as $privacyKey) {
      self::assertFalse($overrides[$privacyKey], "$privacyKey must be off.");
    }
    self::assertSame(0.0, $overrides['traces_sample_rate']);
    self::assertFalse($overrides['request_tracing'], 'A zero sample rate disables tracing entirely.');
    self::assertFalse($overrides['database_tracing']);
  }

}
