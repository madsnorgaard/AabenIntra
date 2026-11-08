<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenintra_audit\Kernel;

use Drupal\aabenintra_audit\AuditEvents;
use Drupal\aabenintra_audit\AuditRepository;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the user entity hooks that feed account-change events.
 */
#[Group('aabenintra_audit')]
final class UserHooksTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'aabenintra_audit'];

  private AuditRepository $repository;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['user', 'aabenintra_audit']);
    $this->installSchema('aabenintra_audit', ['aabenintra_audit_event']);
    $this->repository = $this->container->get('aabenintra_audit.repository');
  }

  /**
   * @return string[]
   */
  private function eventsFor(int $uid): array {
    // findByUid() is newest first; reverse to chronological for readability.
    return array_reverse(array_column($this->repository->findByUid($uid), 'event'));
  }

  public function testAccountLifecycleIsAudited(): void {
    Role::create(['id' => 'editor', 'label' => 'Editor'])->save();

    $user = User::create(['name' => 'anne', 'mail' => 'anne@example.com', 'status' => 1, 'pass' => 'first']);
    $user->save();
    $uid = (int) $user->id();
    $this->assertSame([AuditEvents::USER_CREATED], $this->eventsFor($uid));

    // Unchanged save: nothing new.
    $user->save();
    $this->assertSame([AuditEvents::USER_CREATED], $this->eventsFor($uid));

    $user->setPassword('second')->save();
    $user->setEmail('anne.hansen@example.com')->save();
    $user->addRole('editor')->save();
    $user->block()->save();
    $user->activate()->save();
    $user->removeRole('editor')->save();

    $this->assertSame([
      AuditEvents::USER_CREATED,
      AuditEvents::PASSWORD_CHANGED,
      AuditEvents::MAIL_CHANGED,
      AuditEvents::ROLES_CHANGED,
      AuditEvents::ACCOUNT_BLOCKED,
      AuditEvents::ACCOUNT_UNBLOCKED,
      AuditEvents::ROLES_CHANGED,
    ], $this->eventsFor($uid));

    $rows = array_reverse($this->repository->findByUid($uid));
    $this->assertSame(['from' => 'anne@example.com', 'to' => 'anne.hansen@example.com'], array_intersect_key($rows[2]['details'], ['from' => 1, 'to' => 1]));
    $this->assertSame(['added' => ['editor'], 'removed' => []], array_intersect_key($rows[3]['details'], ['added' => 1, 'removed' => 1]));
    $this->assertTrue($rows[4]['details']['sessions_terminated']);
    $this->assertSame(['added' => [], 'removed' => ['editor']], array_intersect_key($rows[6]['details'], ['added' => 1, 'removed' => 1]));
    $this->assertArrayHasKey('actor_uid', $rows[1]['details']);

    $user->delete();
    $events = $this->eventsFor($uid);
    $this->assertSame(AuditEvents::USER_DELETED, end($events));
    $this->assertCount(8, $events, 'Audit rows survive deletion of the account they describe.');
  }

}
