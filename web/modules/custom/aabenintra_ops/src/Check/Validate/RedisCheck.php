<?php

declare(strict_types=1);

namespace Drupal\aabenintra_ops\Check\Validate;

use Drupal\redis\ClientFactory;
use Drupal\aabenintra_ops\Check\CheckResult;
use Drupal\aabenintra_ops\Check\HealthCheckInterface;
use Drupal\Core\Site\Settings;

/**
 * Redis answers PING when settings.php wires the redis cache backend.
 *
 * Skipped when `redis.connection` is not set (settings.php ships with the
 * Redis block commented out until drupal/redis is enabled on the tenant).
 */
final class RedisCheck implements HealthCheckInterface {

  public function __construct(
    private readonly Settings $settings,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'redis';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Redis connectivity';
  }

  /**
   * {@inheritdoc}
   */
  public function groups(): array {
    return [self::GROUP_VALIDATE];
  }

  /**
   * {@inheritdoc}
   */
  public function run(): CheckResult {
    $connection = $this->settings->get('redis.connection');
    if (!is_array($connection) || $connection === []) {
      return CheckResult::skipped('redis.connection is not set in settings.php; the database cache backend is in use.');
    }
    $host = (string) ($connection['host'] ?? 'unknown');
    if (!class_exists('\Drupal\redis\ClientFactory')) {
      return CheckResult::fail('redis.connection is set but the drupal/redis module is not installed.', ['host' => $host]);
    }
    try {
      $client = ClientFactory::getClient();
      $client->ping();
    }
    catch (\Throwable $e) {
      return CheckResult::fail('Redis PING failed: ' . $e->getMessage(), ['host' => $host]);
    }
    return CheckResult::ok('Redis answered PING.', [
      'host' => $host,
      'interface' => (string) ($connection['interface'] ?? 'auto'),
      'cache_prefix' => (string) ($this->settings->get('cache_prefix') ?? ''),
    ]);
  }

}
