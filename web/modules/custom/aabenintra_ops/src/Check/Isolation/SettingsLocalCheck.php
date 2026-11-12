<?php

declare(strict_types=1);

namespace Drupal\aabenintra_ops\Check\Isolation;

use Drupal\aabenintra_ops\Check\CheckResult;
use Drupal\aabenintra_ops\Check\HealthCheckInterface;

/**
 * No settings.local.php on a production tenant.
 *
 * Settings.php includes it when present, so a stray development file could
 * re-point the database, disable caches or loosen trusted hosts without any
 * trace in the repository.
 */
final class SettingsLocalCheck implements HealthCheckInterface {

  public function __construct(
    private readonly string $appRoot,
    private readonly string $sitePath,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'settings_local';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'No settings.local.php';
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
    $file = $this->appRoot . '/' . $this->sitePath . '/settings.local.php';
    if (file_exists($file)) {
      return CheckResult::fail('settings.local.php is present; production settings must come from settings.php and the environment only.', ['path' => $file]);
    }
    return CheckResult::ok('No settings.local.php in the site directory.');
  }

}
