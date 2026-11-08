<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenintra_ops\Unit\Check;

use Drupal\aabenintra_ops\Check\CheckResult;
use Drupal\aabenintra_ops\Check\CheckStatus;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\aabenintra_ops\Check\CheckResult
 * @group aabenintra_ops
 */
final class CheckResultTest extends UnitTestCase {

  /**
   * @covers ::ok
   * @covers ::warn
   * @covers ::fail
   * @covers ::skipped
   * @covers ::isFailure
   * @covers ::isWarning
   */
  public function testFactoriesSetStatus(): void {
    self::assertSame(CheckStatus::Ok, CheckResult::ok('a')->status);
    self::assertSame(CheckStatus::Warn, CheckResult::warn('b')->status);
    self::assertSame(CheckStatus::Fail, CheckResult::fail('c')->status);
    self::assertSame(CheckStatus::Skipped, CheckResult::skipped('d')->status);

    self::assertTrue(CheckResult::fail('c')->isFailure());
    self::assertFalse(CheckResult::warn('b')->isFailure());
    self::assertTrue(CheckResult::warn('b')->isWarning());
    self::assertFalse(CheckResult::ok('a')->isWarning());
  }

  /**
   * @covers ::detailsLine
   */
  public function testDetailsLineFlattensValues(): void {
    $result = CheckResult::ok('msg', [
      'path' => '/opt/drupal/private',
      'missing' => ['a', 'b'],
      'writable' => TRUE,
      'count' => 3,
      'none' => NULL,
    ]);
    self::assertSame('path=/opt/drupal/private; missing=a, b; writable=yes; count=3; none=-', $result->detailsLine());
    self::assertSame('', CheckResult::ok('msg')->detailsLine());
  }

  /**
   * @covers \Drupal\aabenintra_ops\Check\CheckStatus::severity
   */
  public function testSeverityOrdering(): void {
    self::assertGreaterThan(CheckStatus::Warn->severity(), CheckStatus::Fail->severity());
    self::assertGreaterThan(CheckStatus::Ok->severity(), CheckStatus::Warn->severity());
    self::assertGreaterThan(CheckStatus::Skipped->severity(), CheckStatus::Ok->severity());
  }

}
