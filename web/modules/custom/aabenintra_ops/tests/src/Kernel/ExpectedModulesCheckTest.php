<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenintra_ops\Kernel;

use Drupal\aabenintra_ops\Check\CheckStatus;
use Drupal\aabenintra_ops\Check\HealthCheckInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\aabenintra_ops\Check\Validate\ExpectedModulesCheck;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests ExpectedModulesCheck.
 */
#[CoversClass(ExpectedModulesCheck::class)]
#[Group('aabenintra_ops')]
#[RunTestsInSeparateProcesses]
final class ExpectedModulesCheckTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'aabenintra_ops'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['aabenintra_ops']);
  }

  /**
   * Tests passes when all expected modules are enabled.
   */
  public function testPassesWhenAllExpectedModulesAreEnabled(): void {
    $this->config('aabenintra_ops.settings')->set('expected_modules', ['system', 'user', 'aabenintra_ops'])->save();
    $result = $this->container->get('aabenintra_ops.check.modules')->run();
    self::assertSame(CheckStatus::Ok, $result->status, $result->message);
  }

  /**
   * Tests fails and lists missing modules.
   */
  public function testFailsAndListsMissingModules(): void {
    $this->config('aabenintra_ops.settings')->set('expected_modules', ['system', 'aabenintra_does_not_exist', 'node'])->save();
    $result = $this->container->get('aabenintra_ops.check.modules')->run();
    self::assertSame(CheckStatus::Fail, $result->status);
    self::assertSame(['aabenintra_does_not_exist', 'node'], $result->details['missing']);
  }

  /**
   * Tests skips when nothing is expected.
   */
  public function testSkipsWhenNothingIsExpected(): void {
    $this->config('aabenintra_ops.settings')->set('expected_modules', [])->save();
    $result = $this->container->get('aabenintra_ops.check.modules')->run();
    self::assertSame(CheckStatus::Skipped, $result->status);
  }

  /**
   * The runner collects every tagged check and the container wires them all.
   */
  public function testRunnerCollectsTaggedChecks(): void {
    $runner = $this->container->get('aabenintra_ops.check_runner');
    $ids = array_map(static fn(HealthCheckInterface $c): string => $c->id(), $runner->checksFor(HealthCheckInterface::GROUP_VALIDATE));
    self::assertContains('modules', $ids);
    self::assertContains('theme_front_page', $ids);
    self::assertContains('synthetic_pages', $ids);
    self::assertNotContains('hash_salt', $ids);
    $iso = array_map(static fn(HealthCheckInterface $c): string => $c->id(), $runner->checksFor(HealthCheckInterface::GROUP_ISOLATION));
    self::assertContains('hash_salt', $iso);
    self::assertContains('trusted_hosts', $iso);
    self::assertNotContains('modules', $iso);
  }

}
