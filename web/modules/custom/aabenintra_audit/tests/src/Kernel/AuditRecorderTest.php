<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenintra_audit\Kernel;

use Drupal\aabenintra_audit\AuditEvents;
use Drupal\aabenintra_audit\AuditRecorder;
use Drupal\aabenintra_audit\AuditRepository;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the recorder, the repository and cron retention against a real DB.
 */
#[Group('aabenintra_audit')]
final class AuditRecorderTest extends KernelTestBase {

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
    $this->installConfig(['aabenintra_audit']);
    $this->recorder = $this->container->get('aabenintra_audit.recorder');
    $this->repository = $this->container->get('aabenintra_audit.repository');
  }

  public function testRecordWritesRowWithRequestContext(): void {
    $id = $this->recorder->record(AuditEvents::LOGIN_FAILED, 0, ['reason' => 'unknown_user', 'password' => 'must-not-be-stored'], 'eve');
    $this->assertGreaterThan(0, $id);

    $row = $this->container->get('database')->select('aabenintra_audit_event', 'a')
      ->fields('a')->condition('id', $id)->execute()->fetchAssoc();
    $this->assertIsArray($row);
    $this->assertSame(AuditEvents::LOGIN_FAILED, $row['event']);
    $this->assertSame(0, (int) $row['uid']);
    $this->assertSame('eve', $row['username_attempted']);
    $this->assertSame(RfcLogLevel::NOTICE, (int) $row['severity']);
    // KernelTestBase requests come from 127.0.0.1 and carry no session.
    $this->assertSame('127.0.0.1', $row['ip']);
    $this->assertSame('', $row['session_hash']);
    $this->assertGreaterThan(0, (int) $row['created']);
    $details = json_decode((string) $row['details'], TRUE);
    $this->assertSame(['reason' => 'unknown_user'], $details, 'Secret-looking detail keys are dropped.');
  }

  public function testRecordExternalLoginAndFindByUid(): void {
    $this->recorder->recordExternalLogin('entra', 7, ['subject' => 'abc', 'id_token' => 'secret']);
    $this->recorder->recordExternalLogin('mitid', 0, [], FALSE, 'unknown-cpr-mapping');
    $this->recorder->record(AuditEvents::LOGIN_FAILED, 0, [], 'seven');
    $this->recorder->record(AuditEvents::LOGOUT, 8);

    $rows = $this->repository->findByUid(7, 'seven');
    $this->assertCount(2, $rows, 'Subject rows plus rows where the account name was attempted.');
    $events = array_column($rows, 'event');
    sort($events);
    $this->assertSame([AuditEvents::LOGIN_FAILED, AuditEvents::SSO_LOGIN], $events);
    $sso = array_values(array_filter($rows, static fn(array $r): bool => $r['event'] === AuditEvents::SSO_LOGIN))[0];
    $this->assertSame(['provider' => 'entra', 'method' => 'sso', 'subject' => 'abc'], $sso['details']);
    $this->assertIsInt($sso['created']);

    $this->assertSame([], $this->repository->findByUid(0));
    $this->assertCount(1, $this->repository->findByUid(8));

    $failed = $this->repository->select(['event' => AuditEvents::SSO_LOGIN_FAILED])->execute()->fetchAll();
    $this->assertCount(1, $failed);
    $this->assertSame('unknown-cpr-mapping', $failed[0]->username_attempted);
  }

  public function testRepositoryFilters(): void {
    $db = $this->container->get('database');
    $this->recorder->record(AuditEvents::LOGIN_SUCCESS, 5);
    $id = $this->recorder->record(AuditEvents::LOGIN_SUCCESS, 6);
    $db->update('aabenintra_audit_event')->fields(['ip' => '10.1.2.3', 'created' => 1000])->condition('id', $id)->execute();

    $this->assertCount(1, $this->repository->select(['uid' => 6])->execute()->fetchAll());
    $this->assertCount(1, $this->repository->select(['ip' => '10.1.*'])->execute()->fetchAll());
    $this->assertCount(1, $this->repository->select(['ip' => '10.1.2.3'])->execute()->fetchAll());
    $this->assertCount(0, $this->repository->select(['ip' => '10.1.2.4'])->execute()->fetchAll());
    $this->assertCount(1, $this->repository->select(['to' => 2000])->execute()->fetchAll());
    $this->assertCount(1, $this->repository->select(['from' => 2000])->execute()->fetchAll());
    $this->assertCount(2, $this->repository->select(['event' => AuditEvents::LOGIN_SUCCESS])->execute()->fetchAll());
    $this->assertCount(0, $this->repository->select(['event' => AuditEvents::LOGOUT])->execute()->fetchAll());
    // Newest first.
    $all = $this->repository->select()->execute()->fetchAll();
    $this->assertSame(5, (int) $all[0]->uid);
  }

  public function testRetentionPurgeAndAnonymise(): void {
    $db = $this->container->get('database');
    $now = 1_800_000_000;
    $day = 86400;
    $make = function (string $ip, int $age_days) use ($db, $now, $day): int {
      $id = $this->recorder->record(AuditEvents::LOGIN_SUCCESS, 1);
      $db->update('aabenintra_audit_event')
        ->fields(['ip' => $ip, 'created' => $now - $age_days * $day])
        ->condition('id', $id)
        ->execute();
      return $id;
    };
    $fresh = $make('203.0.113.42', 1);
    $ageing = $make('203.0.113.42', 100);
    $ageing6 = $make('2001:db8::1', 120);
    $ancient = $make('198.51.100.9', 400);

    /** @var \Drupal\aabenintra_audit\Retention $retention */
    $retention = $this->container->get('aabenintra_audit.retention');

    // Defaults: anonymise after 90 days, purge after 365.
    $this->assertSame(3, $retention->anonymise($now), 'Rows older than 90 days get anonymised (incl. the one about to be purged).');
    $this->assertSame(1, $retention->purge($now));

    $ips = $db->select('aabenintra_audit_event', 'a')->fields('a', ['id', 'ip', 'ip_anonymised'])->execute()->fetchAllAssoc('id');
    $this->assertArrayNotHasKey($ancient, $ips);
    $this->assertSame('203.0.113.42', $ips[$fresh]->ip);
    $this->assertSame(0, (int) $ips[$fresh]->ip_anonymised);
    $this->assertSame('203.0.113.0', $ips[$ageing]->ip);
    $this->assertSame(1, (int) $ips[$ageing]->ip_anonymised);
    $this->assertSame('2001:db8::', $ips[$ageing6]->ip);

    // Second run is a no-op.
    $this->assertSame(0, $retention->anonymise($now));
    $this->assertSame(0, $retention->purge($now));

    // 0 disables both steps.
    $this->config('aabenintra_audit.settings')->set('retention_days', 0)->set('anonymise_ip_after_days', 0)->save();
    $this->assertSame(0, $retention->anonymise($now + 1000 * $day));
    $this->assertSame(0, $retention->purge($now + 1000 * $day));

    // hook_cron() wires it up.
    $this->config('aabenintra_audit.settings')->set('retention_days', 1)->save();
    $this->container->get('cron')->run();
    $this->assertSame(0, (int) $db->select('aabenintra_audit_event', 'a')->countQuery()->execute()->fetchField(), 'Cron purged everything older than one day.');
  }

}
