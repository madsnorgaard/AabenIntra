<?php

declare(strict_types=1);

namespace Drupal\aabenintra_gdpr\Collector;

use Drupal\aabenintra_gdpr\ErasureMode;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\node\NodeInterface;
use Drupal\node\NodeStorageInterface;
use Drupal\user\UserInterface;

/**
 * Nodes the subject authored (story, page, event, document, kudos, ...).
 */
final class ContentCollector extends CollectorBase {

  use ReassignTargetTrait;

  private const CHUNK = 50;

  public function __construct(
    ModuleHandlerInterface $moduleHandler,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct($moduleHandler);
  }

  public function id(): string {
    return 'content';
  }

  public function label(): string {
    return 'Authored content';
  }

  public function module(): string {
    return 'node';
  }

  public function count(UserInterface $subject): int {
    return (int) $this->storage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $subject->id())
      ->count()
      ->execute();
  }

  public function collect(UserInterface $subject): array {
    $items = [];
    $nids = $this->storage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $subject->id())
      ->sort('created', 'ASC')
      ->execute();
    foreach (array_chunk($nids, self::CHUNK) as $chunk) {
      foreach ($this->storage()->loadMultiple($chunk) as $node) {
        $item = [
          'nid' => (int) $node->id(),
          'type' => $node->bundle(),
          'title' => $node->label(),
          'status' => $node->isPublished(),
          'created' => $this->iso($node->getCreatedTime()),
          'changed' => $this->iso($node->getChangedTime()),
          'langcode' => $node->language()->getId(),
          'path' => '/node/' . $node->id(),
        ];
        if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
          $item['body'] = (string) $node->get('body')->value;
        }
        $items[] = $item;
      }
    }
    return $items;
  }

  /**
   * Anonymise: every revision authored by the subject is reassigned (uid and
   * revision_uid), the same outcome as core's user_cancel_reassign but
   * without the batch indirection. Delete: nothing here - the entity delete
   * fires node's hook_user_predelete(), which removes the content.
   */
  public function erase(UserInterface $subject, ErasureMode $mode, bool $dryRun): int {
    $count = $this->count($subject);
    if ($dryRun || $mode === ErasureMode::Delete || $count === 0) {
      return $count;
    }
    $target = $this->reassignTarget($this->configFactory, $this->entityTypeManager);
    $storage = $this->storage();
    $vids = array_keys($storage->getQuery()
      ->allRevisions()
      ->accessCheck(FALSE)
      ->condition('uid', $subject->id())
      ->execute());
    foreach (array_chunk($vids, self::CHUNK) as $chunk) {
      foreach ($storage->loadMultipleRevisions($chunk) as $revision) {
        if ($revision instanceof NodeInterface) {
          $revision->setOriginal(clone $revision);
          foreach (array_keys($revision->getTranslationLanguages()) as $langcode) {
            $translation = $revision->getTranslation($langcode);
            $translation->setOwnerId($target);
            $translation->setRevisionUserId($target);
          }
          $revision->setNewRevision(FALSE);
          $revision->save();
        }
      }
    }
    // Revisions created by the subject on other people's nodes.
    $own_revisions = array_keys($storage->getQuery()
      ->allRevisions()
      ->accessCheck(FALSE)
      ->condition('revision_uid', $subject->id())
      ->execute());
    foreach (array_chunk($own_revisions, self::CHUNK) as $chunk) {
      foreach ($storage->loadMultipleRevisions($chunk) as $revision) {
        if ($revision instanceof NodeInterface) {
          $revision->setOriginal(clone $revision);
          $revision->setRevisionUserId($target);
          $revision->setNewRevision(FALSE);
          $revision->save();
        }
      }
    }
    return $count;
  }

  public function erasureNote(ErasureMode $mode): string {
    return $mode === ErasureMode::Anonymise
      ? 'kept; author and revision author reassigned to the configured reassign_uid'
      : 'deleted by core (node hook_user_predelete) together with the account';
  }

  private function storage(): NodeStorageInterface {
    return $this->entityTypeManager->getStorage('node');
  }

}
