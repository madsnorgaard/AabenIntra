<?php

declare(strict_types=1);

namespace Drupal\aabenintra_channels;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Records and reads "follows" (currently topics = channels).
 *
 * One row per user per followed thing; following again toggles it off. Mirrors
 * the reactions ledger pattern. Generic on target_type so people/groups can
 * reuse it later.
 */
final class FollowService {

  public const TYPE_TOPIC = 'topic';

  /**
   * Node bundles that carry channel topics.
   */
  private const TOPIC_BUNDLES = ['story', 'document'];

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Toggles a follow for a user; returns the follow state after the change.
   */
  public function toggle(int $uid, string $type, int $id): bool {
    if ($uid <= 0 || $id <= 0) {
      return FALSE;
    }
    if ($this->isFollowing($uid, $type, $id)) {
      $this->database->delete('aabenintra_follow')
        ->condition('uid', $uid)
        ->condition('target_type', $type)
        ->condition('target_id', $id)
        ->execute();
      $now = FALSE;
    }
    else {
      $this->database->merge('aabenintra_follow')
        ->keys(['uid' => $uid, 'target_type' => $type, 'target_id' => $id])
        ->fields(['created' => $this->time->getRequestTime()])
        ->execute();
      $now = TRUE;
    }
    $this->cacheTagsInvalidator->invalidateTags([
      'aabenintra_channels',
      'aabenintra_follow:' . $uid,
      // The activity feed mixes in followed-topic content.
      'aabenintra_activity',
    ]);
    return $now;
  }

  /**
   * Whether the user follows a given target.
   */
  public function isFollowing(int $uid, string $type, int $id): bool {
    if ($uid <= 0) {
      return FALSE;
    }
    return (bool) $this->database->select('aabenintra_follow', 'f')
      ->fields('f', ['id'])
      ->condition('uid', $uid)
      ->condition('target_type', $type)
      ->condition('target_id', $id)
      ->range(0, 1)
      ->execute()
      ->fetchField();
  }

  /**
   * Target ids a user follows of a given type.
   *
   * @return array<int,int>
   */
  public function followingIds(int $uid, string $type = self::TYPE_TOPIC): array {
    if ($uid <= 0) {
      return [];
    }
    $ids = $this->database->select('aabenintra_follow', 'f')
      ->fields('f', ['target_id'])
      ->condition('uid', $uid)
      ->condition('target_type', $type)
      ->execute()
      ->fetchCol();
    return array_map('intval', $ids);
  }

  /**
   * How many users follow a target.
   */
  public function followerCount(string $type, int $id): int {
    return (int) $this->database->select('aabenintra_follow', 'f')
      ->condition('target_type', $type)
      ->condition('target_id', $id)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Published story/document node ids tagged with any topic the user follows.
   *
   * The bridge consumed by the activity feed, dashboard and /news.
   *
   * @return array<int,int>
   */
  public function nodeIdsForFollowedTopics(int $uid): array {
    $tids = $this->followingIds($uid, self::TYPE_TOPIC);
    if (!$tids) {
      return [];
    }
    $ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('type', self::TOPIC_BUNDLES, 'IN')
      ->condition('field_topics', $tids, 'IN')
      ->execute();
    return array_map('intval', array_values($ids));
  }

}
