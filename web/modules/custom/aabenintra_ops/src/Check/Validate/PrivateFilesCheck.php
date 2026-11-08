<?php

declare(strict_types=1);

namespace Drupal\aabenintra_ops\Check\Validate;

use Drupal\aabenintra_ops\Check\CheckResult;
use Drupal\aabenintra_ops\Check\HealthCheckInterface;
use Drupal\Core\Site\Settings;

/**
 * Private file path is set, exists, is writable and lives outside the docroot.
 */
final class PrivateFilesCheck implements HealthCheckInterface {

  public function __construct(
    private readonly Settings $settings,
    private readonly string $appRoot,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'private_files';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Private file path';
  }

  /**
   * {@inheritdoc}
   */
  public function groups(): array {
    return [self::GROUP_VALIDATE, self::GROUP_ISOLATION];
  }

  /**
   * {@inheritdoc}
   */
  public function run(): CheckResult {
    $path = $this->settings->get('file_private_path');
    if (!is_string($path) || $path === '') {
      return CheckResult::fail('file_private_path is not set; document uploads would be public.');
    }
    if (!is_dir($path)) {
      return CheckResult::fail('Private file path does not exist.', ['path' => $path]);
    }
    if (!is_writable($path)) {
      return CheckResult::fail('Private file path is not writable by the web user.', ['path' => $path]);
    }
    $real = realpath($path);
    $root = realpath($this->appRoot);
    if ($real !== FALSE && $root !== FALSE && str_starts_with($real . '/', $root . '/')) {
      return CheckResult::fail('Private file path is inside the docroot.', ['path' => $real, 'docroot' => $root]);
    }
    return CheckResult::ok('Private file path exists, is writable and is outside the docroot.', ['path' => $real !== FALSE ? $real : $path]);
  }

}
