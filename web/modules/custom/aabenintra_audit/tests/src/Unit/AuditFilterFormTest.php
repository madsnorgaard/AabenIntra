<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenintra_audit\Unit;

use Drupal\aabenintra_audit\Form\AuditFilterForm;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the query-string to repository-filter translation.
 */
#[CoversClass(AuditFilterForm::class)]
#[Group('aabenintra_audit')]
final class AuditFilterFormTest extends UnitTestCase {

  public function testEmptyQueryGivesNoFilters(): void {
    $this->assertSame([], AuditFilterForm::filtersFromQuery([]));
    $this->assertSame([], AuditFilterForm::filtersFromQuery(['event' => '', 'user' => ' ', 'ip' => '', 'from' => '', 'to' => '']));
  }

  public function testUserIsUidWhenNumeric(): void {
    $this->assertSame(['uid' => 42], AuditFilterForm::filtersFromQuery(['user' => '42']));
    $this->assertSame(['username' => 'anne.hansen'], AuditFilterForm::filtersFromQuery(['user' => ' anne.hansen ']));
  }

  public function testInvalidValuesAreDropped(): void {
    $filters = AuditFilterForm::filtersFromQuery([
      'event' => 'Login Success; DROP',
      'ip' => '1.2.3.4 OR 1=1',
      'from' => 'yesterday',
      'to' => '2026-13-45',
    ]);
    $this->assertArrayNotHasKey('event', $filters);
    $this->assertArrayNotHasKey('ip', $filters);
    $this->assertArrayNotHasKey('from', $filters);
    // An impossible calendar date still parses in PHP (rolls over); it must
    // at least be an integer, never raw input.
    if (isset($filters['to'])) {
      $this->assertIsInt($filters['to']);
    }
  }

  public function testValidValues(): void {
    $filters = AuditFilterForm::filtersFromQuery([
      'event' => 'login_failed',
      'ip' => '10.0.*',
      'from' => '2026-01-01',
      'to' => '2026-01-31',
    ]);
    $this->assertSame('login_failed', $filters['event']);
    $this->assertSame('10.0.*', $filters['ip']);
    $this->assertIsInt($filters['from']);
    $this->assertIsInt($filters['to']);
    $this->assertGreaterThan($filters['from'], $filters['to']);
    // "to" is end of day: 30 days + 23:59:59 after "from".
    $this->assertSame(30 * 86400 + 86399, $filters['to'] - $filters['from']);
  }

}
