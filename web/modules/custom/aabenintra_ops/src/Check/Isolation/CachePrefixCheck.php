<?php

declare(strict_types=1);

namespace Drupal\aabenintra_ops\Check\Isolation;

use Drupal\aabenintra_ops\Check\CheckResult;
use Drupal\aabenintra_ops\Check\HealthCheckInterface;
use Drupal\Core\Site\Settings;

/**
 * A cache_prefix is set whenever Redis is the cache backend.
 *
 * Every tenant has its own Redis container, so the keyspace is already
 * isolated; the prefix is defence in depth against a mis-pointed REDIS_HOST
 * and is what makes a leak visible (foreign keys would carry another prefix).
 */
final class CachePrefixCheck implements HealthCheckInterface {

  public function __construct(
    private readonly Settings $settings,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'cache_prefix';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Redis cache prefix';
  }

  /**
   * {@inheritdoc}
   */
  public function groups(): array {
    return [self::GROUP_ISOLATION];
  }

  /**
   * {@inheritdoc}
   */
  public function run(): CheckResult {
    $connection = $this->settings->get('redis.connection');
    if (!is_array($connection) || $connection === []) {
      return CheckResult::skipped('Redis is not the cache backend; nothing to prefix.');
    }
    $prefix = $this->settings->get('cache_prefix');
    $host = (string) ($connection['host'] ?? '');
    if (!is_string($prefix) || $prefix === '') {
      return CheckResult::fail('Redis is enabled without $settings[\'cache_prefix\'].', ['host' => $host]);
    }
    $details = ['prefix' => $prefix, 'host' => $host];
    if (in_array(strtolower($host), ['localhost', '127.0.0.1', 'redis'], TRUE)) {
      return CheckResult::warn('REDIS_HOST looks shared; a tenant should talk to its own Redis container.', $details);
    }
    return CheckResult::ok('cache_prefix is set and Redis host is tenant-specific.', $details);
  }

}
