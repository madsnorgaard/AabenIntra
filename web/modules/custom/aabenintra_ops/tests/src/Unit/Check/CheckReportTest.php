<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenintra_ops\Unit\Check;

use Drupal\aabenintra_ops\Check\CheckReport;
use Drupal\aabenintra_ops\Check\CheckResult;
use Drupal\aabenintra_ops\Check\CheckStatus;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests CheckReport.
 */
#[CoversClass(CheckReport::class)]
#[Group('aabenintra_ops')]
final class CheckReportTest extends UnitTestCase {

  /**
   * Tests empty report passes.
   */
  public function testEmptyReportPasses(): void {
    $report = new CheckReport();
    self::assertSame(0, $report->exitCode());
    self::assertSame(0, $report->exitCode(TRUE));
    self::assertSame(CheckStatus::Skipped, $report->worstStatus());
  }

  /**
   * Tests ok and skipped exit zero.
   */
  public function testOkAndSkippedExitZero(): void {
    $report = new CheckReport();
    $report->add('a', 'A', CheckResult::ok('fine'));
    $report->add('b', 'B', CheckResult::skipped('n/a'));
    self::assertSame(0, $report->exitCode());
    self::assertSame(0, $report->exitCode(TRUE));
    self::assertFalse($report->hasFailures());
    self::assertFalse($report->hasWarnings());
    self::assertSame(CheckStatus::Ok, $report->worstStatus());
  }

  /**
   * Tests warning fails only in strict mode.
   */
  public function testWarningFailsOnlyInStrictMode(): void {
    $report = new CheckReport();
    $report->add('a', 'A', CheckResult::ok('fine'));
    $report->add('b', 'B', CheckResult::warn('hmm'));
    self::assertSame(0, $report->exitCode());
    self::assertSame(1, $report->exitCode(TRUE));
    self::assertTrue($report->hasWarnings());
    self::assertSame(CheckStatus::Warn, $report->worstStatus());
  }

  /**
   * Tests any failure exits one.
   */
  public function testAnyFailureExitsOne(): void {
    $report = new CheckReport();
    $report->add('a', 'A', CheckResult::ok('fine'));
    $report->add('b', 'B', CheckResult::warn('hmm'));
    $report->add('c', 'C', CheckResult::fail('broken'));
    $report->add('d', 'D', CheckResult::skipped('n/a'));
    self::assertSame(1, $report->exitCode());
    self::assertSame(1, $report->exitCode(TRUE));
    self::assertTrue($report->hasFailures());
    self::assertSame(CheckStatus::Fail, $report->worstStatus());
  }

  /**
   * Tests counts and summary.
   */
  public function testCountsAndSummary(): void {
    $report = new CheckReport();
    $report->add('a', 'A', CheckResult::ok('fine'));
    $report->add('b', 'B', CheckResult::ok('fine'));
    $report->add('c', 'C', CheckResult::warn('hmm'));
    $report->add('d', 'D', CheckResult::skipped('n/a'));
    self::assertSame(['ok' => 2, 'warn' => 1, 'fail' => 0, 'skipped' => 1], $report->counts());
    self::assertSame('4 checks: 2 ok, 1 warn, 0 fail, 1 skipped', $report->summary());
    self::assertSame(4, $report->count());
  }

  /**
   * Tests rows are keyed by check id.
   */
  public function testRowsAreKeyedByCheckId(): void {
    $report = new CheckReport();
    $report->add('modules', 'Expected modules', CheckResult::fail('2 missing', ['missing' => ['x', 'y']]));
    $rows = $report->toRows();
    self::assertSame(['modules'], array_keys($rows));
    self::assertSame([
      'check' => 'modules',
      'label' => 'Expected modules',
      'status' => 'fail',
      'message' => '2 missing',
      'details' => 'missing=x, y',
    ], $rows['modules']);
  }

}
