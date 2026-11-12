<?php

declare(strict_types=1);

namespace Drupal\aabenintra_ops\Check\Isolation;

use Drupal\aabenintra_ops\Check\CheckResult;
use Drupal\aabenintra_ops\Check\HealthCheckInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;

/**
 * The database belongs to this tenant only.
 *
 * - the DB user is not root (MYSQL_RANDOM_ROOT_PASSWORD keeps root unusable);
 * - the DB host is a per-tenant container, not the Docker host or a shared
 *   localhost socket;
 * - naming contract: DB name == DB user == tenant slug, host "<slug>_db".
 *
 * Only names and hosts are reported; the password is never read.
 */
final class DatabaseIsolationCheck implements HealthCheckInterface {

  private const SHARED_HOSTS = ['localhost', '127.0.0.1', '::1', 'host.docker.internal', 'db', 'mariadb', 'mysql'];

  public function __construct(
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'database';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Database per tenant';
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
    $options = $this->database->getConnectionOptions();
    $user = (string) ($options['username'] ?? '');
    $name = (string) ($options['database'] ?? '');
    $host = (string) ($options['host'] ?? '');
    $prefix = (string) ($options['prefix'] ?? '');
    $details = ['driver' => (string) ($options['driver'] ?? ''), 'database' => $name, 'user' => $user, 'host' => $host];

    if ($user === 'root') {
      return CheckResult::fail('Drupal connects to the database as root.', $details);
    }
    if ($host === '' || in_array(strtolower($host), self::SHARED_HOSTS, TRUE)) {
      return CheckResult::fail('DB_HOST points at a shared/local database server, not a per-tenant container.', $details);
    }
    if ($prefix !== '') {
      return CheckResult::fail('A table prefix is set; tenants must not share a database.', $details + ['prefix' => $prefix]);
    }

    $slug = $this->slug($name);
    $details['slug'] = $slug;
    $warnings = [];
    if ($name !== $slug) {
      $warnings[] = sprintf('DB name "%s" differs from tenant slug "%s"', $name, $slug);
    }
    if ($user !== $slug) {
      $warnings[] = sprintf('DB user "%s" differs from tenant slug "%s"', $user, $slug);
    }
    if (!str_starts_with($host, $slug . '_')) {
      $warnings[] = sprintf('DB host "%s" does not start with "%s_"', $host, $slug);
    }
    if ($warnings !== []) {
      return CheckResult::warn('Database naming deviates from the tenant contract: ' . implode('; ', $warnings) . '.', $details);
    }
    return CheckResult::ok('Dedicated non-root database user on a per-tenant host; naming follows the contract.', $details);
  }

  /**
   * The tenant slug: configured, else the database name.
   */
  private function slug(string $dbName): string {
    $configured = (string) ($this->configFactory->get('aabenintra_ops.settings')->get('tenant_slug') ?? '');
    return $configured !== '' ? $configured : $dbName;
  }

}
