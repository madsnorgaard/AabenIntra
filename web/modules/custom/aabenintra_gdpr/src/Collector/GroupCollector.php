<?php

declare(strict_types=1);

namespace Drupal\aabenintra_gdpr\Collector;

use Drupal\aabenintra_gdpr\ErasureMode;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\user\UserInterface;

/**
 * Workspace/group memberships (group module) and groups the subject owns.
 *
 * Memberships are deleted in both modes. Groups owned by the subject are
 * reassigned (to reassign_uid, or uid 1 when that is 0, matching the group
 * module's own user_cancel_reassign behaviour) so the workspace survives.
 */
final class GroupCollector extends CollectorBase {

  use ReassignTargetTrait;

  public function __construct(
    ModuleHandlerInterface $moduleHandler,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct($moduleHandler);
  }

  public function id(): string {
    return 'group_memberships';
  }

  public function label(): string {
    return 'Group memberships';
  }

  public function module(): string {
    return 'group';
  }

  public function applies(): bool {
    return parent::applies() && $this->entityTypeManager->hasDefinition('group_relationship');
  }

  public function count(UserInterface $subject): int {
    return count($this->memberships($subject)) + count($this->ownedGroups($subject));
  }

  public function collect(UserInterface $subject): array {
    $items = [];
    foreach ($this->memberships($subject) as $relationship) {
      $group = $relationship->get('gid')->entity ?? NULL;
      $roles = [];
      if ($relationship->hasField('group_roles')) {
        foreach ($relationship->get('group_roles')->referencedEntities() as $role) {
          $roles[] = (string) $role->label();
        }
      }
      $items[] = [
        'kind' => 'membership',
        'group_id' => $group instanceof EntityInterface ? (int) $group->id() : NULL,
        'group' => $group instanceof EntityInterface ? (string) $group->label() : NULL,
        'roles' => $roles,
        'created' => $relationship->hasField('created') ? $this->iso((int) $relationship->get('created')->value) : NULL,
      ];
    }
    foreach ($this->ownedGroups($subject) as $group) {
      $items[] = [
        'kind' => 'owner',
        'group_id' => (int) $group->id(),
        'group' => (string) $group->label(),
      ];
    }
    return $items;
  }

  public function erase(UserInterface $subject, ErasureMode $mode, bool $dryRun): int {
    $memberships = $this->memberships($subject);
    $owned = $this->ownedGroups($subject);
    $count = count($memberships) + count($owned);
    if ($dryRun || $count === 0) {
      return $count;
    }
    $this->entityTypeManager->getStorage('group_relationship')->delete($memberships);
    $target = $this->reassignTarget($this->configFactory, $this->entityTypeManager) ?: 1;
    foreach ($owned as $group) {
      $group->set('uid', $target);
      $group->save();
    }
    return $count;
  }

  public function erasureNote(ErasureMode $mode): string {
    return 'memberships deleted; owned groups reassigned to reassign_uid (or uid 1)';
  }

  /**
   * @return list<\Drupal\Core\Entity\ContentEntityInterface>
   */
  private function memberships(UserInterface $subject): array {
    $storage = $this->entityTypeManager->getStorage('group_relationship');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('plugin_id', 'group_membership')
      ->condition('entity_id', $subject->id())
      ->execute();
    /** @var list<\Drupal\Core\Entity\ContentEntityInterface> $entities */
    $entities = array_values($storage->loadMultiple($ids));
    return $entities;
  }

  /**
   * @return list<\Drupal\Core\Entity\ContentEntityInterface>
   */
  private function ownedGroups(UserInterface $subject): array {
    $storage = $this->entityTypeManager->getStorage('group');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $subject->id())
      ->execute();
    /** @var list<\Drupal\Core\Entity\ContentEntityInterface> $entities */
    $entities = array_values($storage->loadMultiple($ids));
    return $entities;
  }

}
