<?php

declare(strict_types=1);

namespace Drupal\aabenintra_ops\Check\Validate;

use Drupal\aabenintra_ops\Check\CheckResult;
use Drupal\aabenintra_ops\Check\HealthCheckInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * The demo content module should not be enabled on a real tenant.
 *
 * Warn only: the public showcase tenant runs with demo content on purpose.
 */
final class DemoModuleCheck implements HealthCheckInterface {

  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'demo_module';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Demo module not enabled';
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
    if ($this->moduleHandler->moduleExists('aabenintra_demo')) {
      return CheckResult::warn('aabenintra_demo is enabled; it seeds fictional content and must not run on a customer tenant.');
    }
    return CheckResult::ok('aabenintra_demo is not enabled.');
  }

}
