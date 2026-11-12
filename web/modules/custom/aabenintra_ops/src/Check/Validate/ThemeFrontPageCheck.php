<?php

declare(strict_types=1);

namespace Drupal\aabenintra_ops\Check\Validate;

use Drupal\aabenintra_ops\Check\CheckResult;
use Drupal\aabenintra_ops\Check\HealthCheckInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Default theme and front page match what install_prod.sh sets.
 */
final class ThemeFrontPageCheck implements HealthCheckInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'theme_front_page';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Default theme and front page';
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
    $settings = $this->configFactory->get('aabenintra_ops.settings');
    $expectedTheme = (string) ($settings->get('expected_theme') ?? '');
    $expectedFront = (string) ($settings->get('expected_front_page') ?? '');
    $theme = (string) ($this->configFactory->get('system.theme')->get('default') ?? '');
    $front = (string) ($this->configFactory->get('system.site')->get('page.front') ?? '');

    $problems = [];
    if ($expectedTheme !== '' && $theme !== $expectedTheme) {
      $problems[] = sprintf('default theme is "%s", expected "%s"', $theme, $expectedTheme);
    }
    if ($expectedFront !== '' && $front !== $expectedFront) {
      $problems[] = sprintf('front page is "%s", expected "%s"', $front, $expectedFront);
    }
    $details = ['theme' => $theme, 'front_page' => $front];
    if ($problems !== []) {
      return CheckResult::fail(ucfirst(implode('; ', $problems)) . '.', $details);
    }
    return CheckResult::ok('Default theme and front page are as installed.', $details);
  }

}
