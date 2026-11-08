<?php

declare(strict_types=1);

namespace Drupal\aabenintra_gdpr\Collector;

use Drupal\aabenintra_gdpr\ErasureMode;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\user\UserInterface;

/**
 * Kudos the subject received (field_recipient) and a summary of kudos given.
 *
 * Kudos given are ordinary authored nodes and are handled by the content
 * collector (reassigned or deleted). Kudos received are about the subject:
 * in anonymise mode they stay attached to the pseudonymised account so the
 * giver keeps their post; in delete mode they are deleted because the
 * subject is their topic.
 */
final class KudosCollector extends CollectorBase {

  public function __construct(
    ModuleHandlerInterface $moduleHandler,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($moduleHandler);
  }

  public function id(): string {
    return 'kudos_received';
  }

  public function label(): string {
    return 'Kudos received';
  }

  public function module(): string {
    return 'aabenintra_kudos';
  }

  public function applies(): bool {
    return parent::applies() && $this->moduleHandler->moduleExists('node');
  }

  public function count(UserInterface $subject): int {
    return count($this->receivedIds($subject));
  }

  public function collect(UserInterface $subject): array {
    $items = [];
    $storage = $this->entityTypeManager->getStorage('node');
    foreach ($storage->loadMultiple($this->receivedIds($subject)) as $node) {
      $owner = $node->getOwner();
      $items[] = [
        'nid' => (int) $node->id(),
        'badge' => $node->hasField('field_badge') ? (string) $node->get('field_badge')->value : '',
        'message' => $node->hasField('body') ? (string) $node->get('body')->value : '',
        'given_by' => $owner->isAnonymous() ? NULL : $owner->getDisplayName(),
        'created' => $this->iso($node->getCreatedTime()),
      ];
    }
    return $items;
  }

  public function erase(UserInterface $subject, ErasureMode $mode, bool $dryRun): int {
    $ids = $this->receivedIds($subject);
    if ($dryRun || $mode === ErasureMode::Anonymise || $ids === []) {
      return count($ids);
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $storage->delete($storage->loadMultiple($ids));
    return count($ids);
  }

  public function erasureNote(ErasureMode $mode): string {
    return $mode === ErasureMode::Anonymise
      ? 'kept; recipient reference now points at the pseudonymised account'
      : 'deleted (the kudos is about the subject)';
  }

  /**
   * @return list<int>
   */
  private function receivedIds(UserInterface $subject): array {
    $ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'kudos')
      ->condition('field_recipient', $subject->id())
      ->execute();
    return array_values(array_map('intval', $ids));
  }

}
