<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenintra_compliance\Unit;

use Drupal\aabenintra_compliance\ReceiptService;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Merge;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\StatementInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for the read-receipt service against a mocked database.
 *
 * The anonymous-user short circuits and the exact query shape are the
 * contract these tests pin down; the Kernel test covers the real SQL.
 */
#[CoversClass(ReceiptService::class)]
#[Group('aabenintra_compliance')]
final class ReceiptServiceTest extends UnitTestCase {

  private const REQUEST_TIME = 1_700_000_000;

  private Connection&MockObject $database;

  private ReceiptService $service;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->database = $this->createMock(Connection::class);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(self::REQUEST_TIME);
    $this->service = new ReceiptService($this->database, $time);
  }

  /**
   * Anonymous users never get a receipt row.
   */
  public function testRecordIgnoresAnonymous(): void {
    $this->database->expects($this->never())->method('merge');
    $this->service->record(7, 0, 'da');
  }

  /**
   * Recording upserts on (nid, uid, langcode) and stamps the request time.
   */
  public function testRecordMergesOnNaturalKey(): void {
    $merge = $this->createMock(Merge::class);
    $merge->expects($this->once())
      ->method('keys')
      ->with(['nid' => 7, 'uid' => 42, 'langcode' => 'da'])
      ->willReturnSelf();
    $merge->expects($this->once())
      ->method('fields')
      ->with(['created' => self::REQUEST_TIME])
      ->willReturnSelf();
    $merge->expects($this->once())->method('execute');
    $this->database->expects($this->once())
      ->method('merge')
      ->with('aabenintra_read_receipt')
      ->willReturn($merge);

    $this->service->record(7, 42, 'da');
  }

  /**
   * Anonymous users are treated as having read everything (no banner).
   */
  public function testHasReadIsTrueForAnonymousWithoutQuery(): void {
    $this->database->expects($this->never())->method('select');
    $this->assertTrue($this->service->hasRead(7, 0, 'da'));
  }

  /**
   * A matching row means the user has read the node.
   */
  public function testHasReadReflectsRowPresence(): void {
    $this->database->method('select')
      ->with('aabenintra_read_receipt', 'r')
      ->willReturnOnConsecutiveCalls(
        $this->selectReturning('13'),
        $this->selectReturning(FALSE),
      );

    $this->assertTrue($this->service->hasRead(7, 42, 'da'));
    $this->assertFalse($this->service->hasRead(7, 42, 'en'));
  }

  /**
   * The per-node count is an integer regardless of what the driver returns.
   */
  public function testCountForNodeCastsToInt(): void {
    $counted = $this->createMock(SelectInterface::class);
    $counted->method('execute')->willReturn($this->statementReturning('3'));
    $select = $this->createMock(SelectInterface::class);
    $select->method('condition')->willReturnSelf();
    $select->expects($this->once())->method('countQuery')->willReturn($counted);
    $this->database->method('select')->willReturn($select);

    $this->assertSame(3, $this->service->countForNode(7));
  }

  /**
   * Readers come back as uid => created, newest first (ordering is the DB's job).
   */
  public function testReadersReturnsKeyedRows(): void {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAllKeyed')->willReturn([42 => 1_700_000_000, 43 => 1_699_999_000]);
    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->expects($this->once())->method('orderBy')->with('created', 'DESC')->willReturnSelf();
    $select->method('execute')->willReturn($statement);
    $this->database->method('select')->willReturn($select);

    $this->assertSame([42 => 1_700_000_000, 43 => 1_699_999_000], $this->service->readers(7));
  }

  /**
   * Builds a fluent select mock whose fetchField() returns $value.
   */
  private function selectReturning(mixed $value): SelectInterface&MockObject {
    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($this->statementReturning($value));
    return $select;
  }

  private function statementReturning(mixed $value): StatementInterface&MockObject {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')->willReturn($value);
    return $statement;
  }

}
