<?php

declare(strict_types=1);

namespace Drupal\aabenintra_social\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;

/**
 * Records and aggregates per-user reactions on content entities.
 *
 * One reaction per user per entity: reacting again with the same type removes
 * it (toggle); reacting with a different type switches it.
 */
final class ReactionService {

  /**
   * Allowed reaction types and their emoji, in display order.
   */
  public const TYPES = [
    'like' => '👍',
    'celebrate' => '🎉',
    'insightful' => '💡',
    'support' => '❤️',
  ];

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Toggles a reaction for a user on an entity.
   *
   * @return string|null
   *   The user's reaction after the change, or NULL if it was removed.
   */
  public function toggle(EntityInterface $entity, int $uid, string $reaction): ?string {
    if (!isset(self::TYPES[$reaction]) || $uid <= 0) {
      return $this->userReaction($entity, $uid);
    }
    $current = $this->userReaction($entity, $uid);
    if ($current === $reaction) {
      // Same reaction -> remove (toggle off).
      $this->database->delete('aabenintra_reaction')
        ->condition('entity_type', $entity->getEntityTypeId())
        ->condition('entity_id', (int) $entity->id())
        ->condition('uid', $uid)
        ->execute();
      $result = NULL;
    }
    else {
      // New or switched reaction -> upsert.
      $this->database->merge('aabenintra_reaction')
        ->keys([
          'entity_type' => $entity->getEntityTypeId(),
          'entity_id' => (int) $entity->id(),
          'uid' => $uid,
        ])
        ->fields([
          'reaction' => $reaction,
          'created' => $this->time->getRequestTime(),
        ])
        ->execute();
      $result = $reaction;
    }
    $this->cacheTagsInvalidator->invalidateTags($entity->getCacheTagsToInvalidate());
    return $result;
  }

  /**
   * Returns the reaction this user currently holds, or NULL.
   */
  public function userReaction(EntityInterface $entity, int $uid): ?string {
    if ($uid <= 0) {
      return NULL;
    }
    $value = $this->database->select('aabenintra_reaction', 'r')
      ->fields('r', ['reaction'])
      ->condition('entity_type', $entity->getEntityTypeId())
      ->condition('entity_id', (int) $entity->id())
      ->condition('uid', $uid)
      ->execute()
      ->fetchField();
    return $value === FALSE ? NULL : (string) $value;
  }

  /**
   * Returns counts keyed by reaction type (all types present, zero-filled).
   *
   * @return array<string,int>
   */
  public function counts(EntityInterface $entity): array {
    $counts = array_fill_keys(array_keys(self::TYPES), 0);
    $rows = $this->database->select('aabenintra_reaction', 'r')
      ->fields('r', ['reaction'])
      ->condition('entity_type', $entity->getEntityTypeId())
      ->condition('entity_id', (int) $entity->id())
      ->execute();
    foreach ($rows as $row) {
      if (isset($counts[$row->reaction])) {
        $counts[$row->reaction]++;
      }
    }
    return $counts;
  }

}
