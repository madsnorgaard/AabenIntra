<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenintra_compliance\Kernel;

use Drupal\aabenintra_compliance\ReceiptService;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Exercises the read-receipt service against the real schema.
 */
#[CoversClass(ReceiptService::class)]
#[Group('aabenintra_compliance')]
final class ReceiptServiceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'aabenintra_compliance'];

  private ReceiptService $receipts;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('aabenintra_compliance', ['aabenintra_read_receipt']);
    $this->receipts = $this->container->get('aabenintra_compliance.receipts');
  }

  /**
   * The service is wired with the id the controllers and hooks resolve.
   */
  public function testServiceIsRegistered(): void {
    $this->assertInstanceOf(ReceiptService::class, $this->receipts);
  }

  /**
   * A receipt is scoped to node + user + language and is idempotent.
   */
  public function testRecordAndHasRead(): void {
    $this->assertFalse($this->receipts->hasRead(1, 2, 'da'));

    $this->receipts->record(1, 2, 'da');
    $this->assertTrue($this->receipts->hasRead(1, 2, 'da'));
    // Another translation of the same node still needs its own acknowledgement.
    $this->assertFalse($this->receipts->hasRead(1, 2, 'en'));
    // Another user is unaffected.
    $this->assertFalse($this->receipts->hasRead(1, 3, 'da'));

    // Recording twice must not violate the unique key or double count.
    $this->receipts->record(1, 2, 'da');
    $this->assertSame(1, $this->receipts->countForNode(1));
  }

  /**
   * Anonymous visitors are never recorded and never see the banner.
   */
  public function testAnonymousIsNeverRecorded(): void {
    $this->receipts->record(1, 0, 'da');
    $this->assertSame(0, $this->receipts->countForNode(1));
    $this->assertTrue($this->receipts->hasRead(1, 0, 'da'));
  }

  /**
   * Counts and reader lists aggregate across languages.
   */
  public function testCountAndReaders(): void {
    $this->receipts->record(5, 10, 'da');
    $this->receipts->record(5, 11, 'en');
    $this->receipts->record(6, 10, 'da');

    $this->assertSame(2, $this->receipts->countForNode(5));
    $this->assertSame(1, $this->receipts->countForNode(6));
    $this->assertSame(0, $this->receipts->countForNode(7));

    $readers = $this->receipts->readers(5);
    // Both rows share the request time, so only the set of readers is stable.
    $this->assertEqualsCanonicalizing([10, 11], array_keys($readers), 'Keyed by uid.');
    foreach ($readers as $created) {
      $this->assertGreaterThan(0, $created);
    }
    $this->assertSame([], $this->receipts->readers(7));
  }

}
