<?php

declare(strict_types=1);

namespace Drupal\aabenintra_ops\Check\Validate;

use Drupal\aabenintra_ops\Check\CheckResult;
use Drupal\aabenintra_ops\Check\HealthCheckInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Key config objects shipped by the recipes and install hooks exist.
 */
final class RecipeConfigCheck implements HealthCheckInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'recipe_config';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Recipe config present';
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
    $expected = $this->configFactory->get('aabenintra_ops.settings')->get('expected_config');
    if (!is_array($expected) || $expected === []) {
      return CheckResult::skipped('No expected_config configured in aabenintra_ops.settings.');
    }
    $missing = [];
    foreach ($expected as $name) {
      if (!is_string($name) || $this->configFactory->get($name)->isNew()) {
        $missing[] = (string) $name;
      }
    }
    if ($missing !== []) {
      return CheckResult::fail(sprintf('%d of %d expected config objects are missing; a recipe did not apply.', count($missing), count($expected)), ['missing' => $missing]);
    }
    return CheckResult::ok(sprintf('All %d expected config objects exist.', count($expected)));
  }

}
