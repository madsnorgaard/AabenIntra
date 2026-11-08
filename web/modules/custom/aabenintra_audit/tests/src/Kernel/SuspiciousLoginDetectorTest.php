<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenintra_audit\Kernel;

use Drupal\aabenintra_audit\AuditEvents;
use Drupal\aabenintra_audit\AuditRecorder;
use Drupal\aabenintra_audit\AuditRepository;
use Drupal\Core\Test\AssertMailTrait;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the optional suspicious-login rules end to end (event -> mail -> row).
 */
#[Group('aabenintra_audit')]
final class SuspiciousLoginDetectorTest extends KernelTestBase {

  use AssertMailTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'aabenintra_audit'];

  private AuditRecorder $recorder;
  private AuditRepository $repository;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('aabenintra_audit', ['aabenintra_audit_event']);
    $this->installConfig(['system', 'aabenintra_audit']);
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $this->recorder = $this->container->get('aabenintra_audit.recorder');
    $this->repository = $this->container->get('aabenintra_audit.repository');
  }

  private function alertCount(): int {
    return count($this->repository->select(['event' => AuditEvents::ALERT_SENT])->execute()->fetchAll());
  }

  public function testDisabledByDefault(): void {
    for ($i = 0; $i < 10; $i++) {
      $this->recorder->record(AuditEvents::LOGIN_FAILED, 0, [], 'eve');
    }
    $this->assertSame(0, $this->alertCount());
    $this->assertCount(0, $this->getMails());
  }

  public function testFailedBurstAlertsOnceAtThreshold(): void {
    $this->config('aabenintra_audit.settings')
      ->set('alerts.enabled', TRUE)
      ->set('alerts.mail', 'security@example.com')
      ->set('alerts.failed_threshold', 3)
      ->save();

    $this->recorder->record(AuditEvents::LOGIN_FAILED, 0, [], 'eve');
    $this->recorder->record(AuditEvents::LOGIN_BLOCKED_FLOOD, 0, [], 'eve');
    $this->assertSame(0, $this->alertCount());
    $this->recorder->record(AuditEvents::LOGIN_FAILED, 0, [], 'eve');
    $this->assertSame(1, $this->alertCount());
    $this->recorder->record(AuditEvents::LOGIN_FAILED, 0, [], 'eve');
    $this->assertSame(1, $this->alertCount(), 'Only the threshold-reaching attempt alerts.');

    $mails = $this->getMails(['id' => 'aabenintra_audit_suspicious_login']);
    $this->assertCount(1, $mails);
    $this->assertSame('security@example.com', $mails[0]['to']);
    $this->assertStringContainsString('failed_burst', (string) $mails[0]['subject']);
    $this->assertStringContainsString('127.0.0.1', (string) $mails[0]['body']);

    $alert = $this->repository->select(['event' => AuditEvents::ALERT_SENT])->execute()->fetchAll();
    $details = AuditRepository::decode((array) $alert[0])['details'];
    $this->assertSame('failed_burst', $details['rule']);
    $this->assertTrue($details['mail_sent']);
  }

  public function testNewIpAlertsOnlyForUsersWithHistory(): void {
    $db = $this->container->get('database');
    $this->config('aabenintra_audit.settings')
      ->set('alerts.enabled', TRUE)
      ->set('alerts.mail', 'security@example.com')
      ->save();

    // First ever login: no history, no alert.
    $first = $this->recorder->record(AuditEvents::LOGIN_SUCCESS, 3);
    $this->assertSame(0, $this->alertCount());
    // Same IP again: known, no alert.
    $second = $this->recorder->record(AuditEvents::LOGIN_SUCCESS, 3);
    $this->assertSame(0, $this->alertCount());

    // Pretend both earlier logins came from another address.
    $db->update('aabenintra_audit_event')->fields(['ip' => '10.9.9.9'])->condition('id', [$first, $second], 'IN')->execute();
    $this->recorder->record(AuditEvents::LOGIN_SUCCESS, 3);
    $this->assertSame(1, $this->alertCount());
    $mails = $this->getMails(['id' => 'aabenintra_audit_suspicious_login']);
    $this->assertStringContainsString('new_ip', (string) $mails[0]['subject']);

    // Another user with no history at all: still no alert.
    $this->recorder->record(AuditEvents::SSO_LOGIN, 4, ['provider' => 'entra']);
    $this->assertSame(1, $this->alertCount());
  }

}
