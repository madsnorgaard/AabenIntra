<?php

declare(strict_types=1);

namespace Drupal\aabenintra_ops\Check\Validate;

use Drupal\aabenintra_ops\Check\CheckResult;
use Drupal\aabenintra_ops\Check\HealthCheckInterface;
use Drupal\Core\File\FileSystemInterface;

/**
 * Public files directory is writable (image styles, aggregated assets).
 *
 * The first production bootstrap once ran as root and left files/styles owned
 * by root, so image derivatives returned 500 while sources served fine. This
 * check also looks at the styles/ subdirectory for that reason.
 */
final class PublicFilesCheck implements HealthCheckInterface {

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'public_files';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Public files writable';
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
    $path = $this->fileSystem->realpath('public://');
    if ($path === FALSE || !is_dir($path)) {
      return CheckResult::fail('Public files directory does not exist.', ['path' => (string) $path]);
    }
    if (!is_writable($path)) {
      return CheckResult::fail('Public files directory is not writable.', ['path' => $path]);
    }
    $styles = $path . '/styles';
    if (is_dir($styles) && !is_writable($styles)) {
      return CheckResult::fail('files/styles exists but is not writable (owned by root after a bootstrap run as root?).', ['path' => $styles]);
    }
    return CheckResult::ok('Public files directory is writable.', ['path' => $path]);
  }

}
