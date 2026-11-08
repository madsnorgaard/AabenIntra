<?php

declare(strict_types=1);

namespace Drupal\aabenintra_ops\Check\Validate;

use Drupal\aabenintra_ops\Check\CheckResult;
use Drupal\aabenintra_ops\Check\HealthCheckInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Every module the install script and recipes enable must still be enabled.
 */
final class ExpectedModulesCheck implements HealthCheckInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'modules';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Expected modules enabled';
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
    $expected = $this->configFactory->get('aabenintra_ops.settings')->get('expected_modules');
    if (!is_array($expected) || $expected === []) {
      return CheckResult::skipped('No expected_modules configured in aabenintra_ops.settings.');
    }
    $missing = [];
    foreach ($expected as $module) {
      if (!is_string($module) || !$this->moduleHandler->moduleExists($module)) {
        $missing[] = (string) $module;
      }
    }
    if ($missing !== []) {
      return CheckResult::fail(sprintf('%d of %d expected modules are not enabled.', count($missing), count($expected)), ['missing' => $missing]);
    }
    return CheckResult::ok(sprintf('All %d expected modules are enabled.', count($expected)));
  }

}
