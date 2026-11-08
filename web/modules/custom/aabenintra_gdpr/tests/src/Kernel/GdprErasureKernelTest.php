<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenintra_gdpr\Kernel;

use Drupal\aabenintra_gdpr\ErasureMode;
use Drupal\aabenintra_gdpr\ExportRequestStorage;
use Drupal\aabenintra_gdpr\GdprEraser;
use Drupal\aabenintra_gdpr\GdprExporter;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Export and erasure against real tables (user + compliance receipts).
 */
#[Group('aabenintra_gdpr')]
final class GdprErasureKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'field',
    'text',
    'filter',
    'node',
    'aabenintra_compliance',
    'aabenintra_gdpr',
  ];

  /**
   * {@inheritdoc}
   *
   * The private stream wrapper is only registered when file_private_path is
   * set before the kernel boots, so it is configured here, not in setUp().
   */
  protected function setUpFilesystem(): void {
    parent::setUpFilesystem();
    $private = $this->siteDirectory . '/private';
    if (!is_dir($private)) {
      mkdir($private, 0775, TRUE);
    }
    $this->setSetting('file_private_path', $private);
  }

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('node');
    $this->installSchema('user', ['users_data']);
    $this->installSchema('node', ['node_access']);
    $this->installSchema('aabenintra_compliance', ['aabenintra_read_receipt']);
    $this->installSchema('aabenintra_gdpr', ['aabenintra_gdpr_request', 'aabenintra_gdpr_erasure']);
    $this->installConfig(['system', 'user', 'aabenintra_gdpr']);
    // The first account saved in a kernel test gets uid 1, which the eraser
    // refuses as the site owner. Occupy that slot so test subjects are
    // ordinary accounts.
    User::create(['name' => 'site-owner', 'mail' => 'owner@example.org', 'status' => 1])->save();
  }

  private function createSubject(string $name = 'jane'): UserInterface {
    $user = User::create([
      'name' => $name,
      'mail' => $name . '@example.org',
      'status' => 1,
    ]);
    $user->save();
    return $user;
  }

  private function receiptCount(int $uid): int {
    return (int) $this->container->get('database')
      ->select('aabenintra_read_receipt', 'r')
      ->condition('uid', $uid)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  public function testExportContainsReceiptsAndWritesPrivateFile(): void {
    $subject = $this->createSubject();
    $uid = (int) $subject->id();
    $receipts = $this->container->get('aabenintra_compliance.receipts');
    $receipts->record(11, $uid, 'da');
    $receipts->record(12, $uid, 'da');
    $this->container->get('user.data')->set('aabenintra_theme', $uid, 'prefs', ['accent' => 'sky']);

    /** @var \Drupal\aabenintra_gdpr\GdprExporter $exporter */
    $exporter = $this->container->get('aabenintra_gdpr.exporter');
    $doc = $exporter->buildDocument($subject);

    $this->assertSame(GdprExporter::SCHEMA_VERSION, $doc['schema_version']);
    $this->assertSame($uid, $doc['subject']['uid']);
    $this->assertSame('jane@example.org', $doc['subject']['mail']);
    $this->assertArrayHasKey('read_receipts', $doc['sections']);
    $this->assertSame(2, $doc['sections']['read_receipts']['count']);
    $this->assertSame([11, 12], array_column($doc['sections']['read_receipts']['items'], 'nid'));
    // Collectors whose module is not installed are absent, not empty.
    $this->assertArrayNotHasKey('reactions', $doc['sections']);
    $this->assertArrayNotHasKey('preferences', $doc['sections'], 'aabenintra_theme is not installed in this test.');
    $this->assertArrayHasKey('content', $doc['sections']);
    $this->assertArrayHasKey('export_requests', $doc['sections']);

    $uri = $exporter->export($subject);
    $this->assertStringStartsWith(GdprExporter::EXPORT_DIR . '/' . $uid . '-', $uri);
    $this->assertFileExists($uri);
    $decoded = json_decode((string) file_get_contents($uri), TRUE);
    $this->assertSame(1, $decoded['schema_version']);
    $this->assertSame(2, $decoded['sections']['read_receipts']['count']);
  }

  public function testDryRunLeavesEverythingIntact(): void {
    $subject = $this->createSubject();
    $uid = (int) $subject->id();
    $this->container->get('aabenintra_compliance.receipts')->record(11, $uid, 'da');

    /** @var \Drupal\aabenintra_gdpr\GdprEraser $eraser */
    $eraser = $this->container->get('aabenintra_gdpr.eraser');
    $report = $eraser->erase($subject, ErasureMode::Delete, dryRun: TRUE);

    $this->assertTrue($report->dryRun);
    $this->assertSame(1, $report->sections['read_receipts']['count']);
    $this->assertSame(1, $this->receiptCount($uid));
    $reloaded = User::load($uid);
    $this->assertInstanceOf(UserInterface::class, $reloaded);
    $this->assertSame('jane', $reloaded->getAccountName());
    $this->assertSame(0, (int) $this->container->get('database')->select('aabenintra_gdpr_erasure')->countQuery()->execute()->fetchField(), 'A dry run writes no audit row.');
  }

  public function testDeleteModeRemovesAccountAndReceipts(): void {
    $subject = $this->createSubject();
    $uid = (int) $subject->id();
    $this->container->get('aabenintra_compliance.receipts')->record(11, $uid, 'da');
    $this->container->get('aabenintra_compliance.receipts')->record(12, $uid, 'en');

    /** @var \Drupal\aabenintra_gdpr\GdprEraser $eraser */
    $eraser = $this->container->get('aabenintra_gdpr.eraser');
    $report = $eraser->erase($subject, ErasureMode::Delete);

    $this->assertSame(2, $report->sections['read_receipts']['count']);
    $this->assertSame(0, $this->receiptCount($uid));
    $this->assertNull(User::load($uid));
    $log = $this->container->get('database')->select('aabenintra_gdpr_erasure', 'e')->fields('e')->execute()->fetchAssoc();
    $this->assertIsArray($log);
    $this->assertSame('delete', $log['mode']);
    $this->assertSame($eraser->subjectHash($uid), $log['subject_hash']);
    $this->assertSame(2, json_decode((string) $log['counts'], TRUE)['read_receipts']);
  }

  public function testAnonymiseModeKeepsReceiptsAndPseudonymisesAccount(): void {
    $subject = $this->createSubject();
    $uid = (int) $subject->id();
    $this->container->get('aabenintra_compliance.receipts')->record(11, $uid, 'da');
    $this->container->get('user.data')->set('aabenintra_theme', $uid, 'prefs', ['accent' => 'sky']);

    /** @var \Drupal\aabenintra_gdpr\GdprEraser $eraser */
    $eraser = $this->container->get('aabenintra_gdpr.eraser');
    $report = $eraser->erase($subject, ErasureMode::Anonymise);

    $this->assertSame(1, $report->sections['read_receipts']['count']);
    $this->assertSame(1, $this->receiptCount($uid), 'Receipts survive anonymisation as audit evidence.');
    $reloaded = User::load($uid);
    $this->assertInstanceOf(UserInterface::class, $reloaded);
    $this->assertSame('deleted-user-' . $uid, $reloaded->getAccountName());
    $this->assertSame('deleted-' . $uid . '@invalid.invalid', $reloaded->getEmail());
    $this->assertTrue($reloaded->isBlocked());
    $this->assertNull($this->container->get('user.data')->get('aabenintra_theme', $uid, 'prefs'));
  }

  public function testRefusesProtectedAccountsWithoutForce(): void {
    Role::create(['id' => GdprEraser::ROLE_ADMINISTRATOR, 'label' => 'Administrator'])->save();
    $admin = $this->createSubject('boss');
    $admin->addRole(GdprEraser::ROLE_ADMINISTRATOR);
    $admin->save();
    /** @var \Drupal\aabenintra_gdpr\GdprEraser $eraser */
    $eraser = $this->container->get('aabenintra_gdpr.eraser');
    $this->assertNotEmpty($eraser->refusals($admin));
    try {
      $eraser->erase($admin, ErasureMode::Anonymise);
      $this->fail('Expected a refusal.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString(GdprEraser::ROLE_ADMINISTRATOR, $e->getMessage());
    }
    $this->assertSame('boss', User::load((int) $admin->id())->getAccountName());

    $eraser->erase($admin, ErasureMode::Anonymise, force: TRUE);
    $this->assertSame('deleted-user-' . $admin->id(), User::load((int) $admin->id())->getAccountName());
  }

  public function testExportRequestLifecycle(): void {
    $subject = $this->createSubject();
    $uid = (int) $subject->id();
    /** @var \Drupal\aabenintra_gdpr\ExportRequestStorage $requests */
    $requests = $this->container->get('aabenintra_gdpr.requests');
    $id = $requests->create($uid);
    $this->assertSame(ExportRequestStorage::STATUS_PENDING, $requests->load($id)['status']);

    /** @var \Drupal\Core\Queue\QueueWorkerManagerInterface $manager */
    $manager = $this->container->get('plugin.manager.queue_worker');
    $worker = $manager->createInstance('aabenintra_gdpr_export');
    $worker->processItem(['request_id' => $id, 'uid' => $uid]);

    $row = $requests->load($id);
    $this->assertSame(ExportRequestStorage::STATUS_READY, $row['status'], $row['message']);
    $this->assertFileExists($row['uri']);
    $this->assertNotSame('', $row['token_hash']);
    $this->assertGreaterThan($row['completed'], $row['expires']);

    // One e-mail went out with a download link.
    $mails = $this->container->get('state')->get('system.test_mail_collector', []);
    $this->assertCount(1, $mails);
    $this->assertSame('jane@example.org', $mails[0]['to']);
    $this->assertStringContainsString('/gdpr/export/', $mails[0]['body']);

    // Erasing the subject removes the request row and the file.
    $this->container->get('aabenintra_gdpr.eraser')->erase($subject, ErasureMode::Delete);
    $this->assertNull($requests->load($id));
    $this->assertFileDoesNotExist($row['uri']);
  }

}
