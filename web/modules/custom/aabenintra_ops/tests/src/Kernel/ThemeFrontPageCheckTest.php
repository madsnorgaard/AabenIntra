<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenintra_ops\Kernel;

use Drupal\aabenintra_ops\Check\CheckStatus;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\aabenintra_ops\Check\Validate\ThemeFrontPageCheck;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests ThemeFrontPageCheck.
 */
#[CoversClass(ThemeFrontPageCheck::class)]
#[Group('aabenintra_ops')]
#[RunTestsInSeparateProcesses]
final class ThemeFrontPageCheckTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'aabenintra_ops'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'aabenintra_ops']);
  }

  /**
   * Tests passes when theme and front page match.
   */
  public function testPassesWhenThemeAndFrontPageMatch(): void {
    $this->config('system.theme')->set('default', 'aabenintra')->save();
    $this->config('system.site')->set('page.front', '/dashboard')->save();
    $result = $this->container->get('aabenintra_ops.check.theme_front_page')->run();
    self::assertSame(CheckStatus::Ok, $result->status, $result->message);
    self::assertSame('aabenintra', $result->details['theme']);
    self::assertSame('/dashboard', $result->details['front_page']);
  }

  /**
   * Tests fails on wrong theme or front page.
   */
  public function testFailsOnWrongThemeOrFrontPage(): void {
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->config('system.site')->set('page.front', '/dashboard')->save();
    $result = $this->container->get('aabenintra_ops.check.theme_front_page')->run();
    self::assertSame(CheckStatus::Fail, $result->status);
    self::assertStringContainsString('theme is "stark", expected "aabenintra"', $result->message);

    $this->config('system.theme')->set('default', 'aabenintra')->save();
    $this->config('system.site')->set('page.front', '/node')->save();
    $result = $this->container->get('aabenintra_ops.check.theme_front_page')->run();
    self::assertSame(CheckStatus::Fail, $result->status);
    self::assertStringContainsString('page is "/node", expected "/dashboard"', $result->message);
  }

  /**
   * Tests operator can change expectations.
   */
  public function testOperatorCanChangeExpectations(): void {
    $this->config('aabenintra_ops.settings')->set('expected_theme', 'stark')->set('expected_front_page', '/node')->save();
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->config('system.site')->set('page.front', '/node')->save();
    $result = $this->container->get('aabenintra_ops.check.theme_front_page')->run();
    self::assertSame(CheckStatus::Ok, $result->status, $result->message);
  }

}
