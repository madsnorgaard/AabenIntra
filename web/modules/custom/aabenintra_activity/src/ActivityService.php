<?php

declare(strict_types=1);

namespace Drupal\aabenintra_activity;

use Drupal\comment\CommentInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Records and reads the intranet activity stream.
 *
 * A write-time log: content publications, comments and kudos are appended to a
 * single table by fan-out hooks, then read back with one org + group scoped
 * query. Visibility rules:
 *  - group content (gid set) is shown only to members of that group;
 *  - other content is shown org-wide (org_unit NULL) or to the org unit it
 *    targets and any of its descendant units (a division post reaches its
 *    teams), mirroring the dashboard cascade.
 */
final class ActivityService {

  /**
   * Node bundles that generate "published" (or "kudos") activity.
   */
  public const BUNDLES = ['story', 'event', 'document', 'tip', 'kudos'];

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Appends (idempotently) one activity row and invalidates the feed cache.
   *
   * @param array<string,mixed> $opts
   *   Optional: entity_type, ref_id, target_uid, bundle, org_unit, gid,
   *   langcode, created.
   */
  public function log(string $verb, int $actorUid, int $entityId, array $opts = []): void {
    if ($actorUid <= 0 || $entityId <= 0 || $verb === '') {
      return;
    }
    $this->database->merge('aabenintra_activity')
      ->keys([
        'verb' => $verb,
        'entity_type' => (string) ($opts['entity_type'] ?? 'node'),
        'entity_id' => $entityId,
        'actor_uid' => $actorUid,
        'ref_id' => (int) ($opts['ref_id'] ?? 0),
      ])
      ->fields([
        'target_uid' => isset($opts['target_uid']) ? (int) $opts['target_uid'] : NULL,
        'bundle' => (string) ($opts['bundle'] ?? ''),
        'org_unit' => isset($opts['org_unit']) && $opts['org_unit'] !== NULL ? (int) $opts['org_unit'] : NULL,
        'gid' => isset($opts['gid']) && $opts['gid'] !== NULL ? (int) $opts['gid'] : NULL,
        'langcode' => (string) ($opts['langcode'] ?? 'und'),
        'created' => (int) ($opts['created'] ?? $this->time->getRequestTime()),
      ])
      ->execute();
    $this->cacheTagsInvalidator->invalidateTags(['aabenintra_activity']);
  }

  /**
   * Logs a content node ("published", or "kudos" for the kudos bundle).
   *
   * @param array<string,mixed> $opts
   *   May carry target_uid (kudos recipient); scope fields are derived here.
   */
  public function logNode(NodeInterface $node, string $verb, array $opts = []): void {
    $opts += [
      'bundle' => $node->bundle(),
      'langcode' => $node->language()->getId(),
      'created' => (int) $node->getCreatedTime(),
      'org_unit' => $this->nodeOrgUnit($node),
      'gid' => $this->nodeGroupId((int) $node->id()),
    ];
    $this->log($verb, (int) $node->getOwnerId(), (int) $node->id(), $opts);
  }

  /**
   * Logs a comment as a "commented" event on its host node.
   */
  public function logComment(NodeInterface $node, CommentInterface $comment): void {
    $this->log('commented', (int) $comment->getOwnerId(), (int) $node->id(), [
      'ref_id' => (int) $comment->id(),
      'bundle' => $node->bundle(),
      'langcode' => $comment->language()->getId(),
      'created' => (int) $comment->getCreatedTime(),
      'org_unit' => $this->nodeOrgUnit($node),
      'gid' => $this->nodeGroupId((int) $node->id()),
    ]);
  }

  /**
   * Returns the activity visible to an account, newest first.
   *
   * @param array<int,int> $extraNodeIds
   *   Extra subject node ids to include regardless of org/group scope (e.g.
   *   content in channels the user follows). Resolved by the caller so this
   *   module stays independent of the channels module.
   *
   * @return array{rows: array<int,object>, total: int}
   */
  public function feed(AccountInterface $account, int $limit, int $offset, array $extraNodeIds = []): array {
    $lineage = $this->orgLineage($account);
    $groupIds = $this->userGroupIds($account);

    $query = $this->database->select('aabenintra_activity', 'a')->fields('a');
    $this->applyScope($query, $lineage, $groupIds, $extraNodeIds);
    // Count the full scoped set before paging (countQuery clones, leaving the
    // query reusable for the ranged fetch below).
    $total = (int) $query->countQuery()->execute()->fetchField();

    $query->orderBy('created', 'DESC')->orderBy('id', 'DESC')->range($offset, $limit);
    $rows = $query->execute()->fetchAll();

    return ['rows' => $rows, 'total' => $total];
  }

  /**
   * Rebuilds the log from existing content (idempotent via merge).
   *
   * @return array<string,int>
   *   Counts keyed by verb.
   */
  public function backfill(): array {
    $counts = ['published' => 0, 'kudos' => 0, 'commented' => 0];
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    $nids = $nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::BUNDLES, 'IN')
      ->condition('status', 1)
      ->execute();
    foreach ($nodeStorage->loadMultiple($nids) as $node) {
      $verb = $node->bundle() === 'kudos' ? 'kudos' : 'published';
      $opts = [];
      if ($verb === 'kudos' && $node->hasField('field_recipient') && !$node->get('field_recipient')->isEmpty()) {
        $opts['target_uid'] = (int) $node->get('field_recipient')->target_id;
      }
      $this->logNode($node, $verb, $opts);
      $counts[$verb]++;
    }

    if ($this->entityTypeManager->hasDefinition('comment')) {
      $commentStorage = $this->entityTypeManager->getStorage('comment');
      $cids = $commentStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', 1)
        ->condition('entity_type', 'node')
        ->execute();
      foreach ($commentStorage->loadMultiple($cids) as $comment) {
        $node = $nodeStorage->load((int) $comment->getCommentedEntityId());
        if ($node instanceof NodeInterface) {
          $this->logComment($node, $comment);
          $counts['commented']++;
        }
      }
    }

    return $counts;
  }

  /**
   * Group ids the account is a member of (empty when group is not installed).
   *
   * @return array<int,int>
   */
  public function userGroupIds(AccountInterface $account): array {
    if (!$this->database->schema()->tableExists('group_relationship_field_data')) {
      return [];
    }
    $ids = $this->database->select('group_relationship_field_data', 'g')
      ->fields('g', ['gid'])
      ->condition('entity_id', (int) $account->id())
      ->condition('plugin_id', '%group_membership', 'LIKE')
      ->execute()
      ->fetchCol();
    return array_map('intval', $ids);
  }

  /**
   * The account's org unit plus all its ancestors (term ids).
   *
   * @return array<int,int>
   */
  private function orgLineage(AccountInterface $account): array {
    $user = $this->entityTypeManager->getStorage('user')->load($account->id());
    if ($user && $user->hasField('field_primary_org_unit') && !$user->get('field_primary_org_unit')->isEmpty()) {
      $tid = (int) $user->get('field_primary_org_unit')->target_id;
      $parents = $this->entityTypeManager->getStorage('taxonomy_term')->loadAllParents($tid);
      return array_map('intval', array_keys($parents));
    }
    return [];
  }

  /**
   * Adds the visibility WHERE clause to a feed query.
   *
   * @param array<int,int> $lineage
   * @param array<int,int> $groupIds
   * @param array<int,int> $extraNodeIds
   */
  private function applyScope(SelectInterface $query, array $lineage, array $groupIds, array $extraNodeIds = []): void {
    $scope = $query->orConditionGroup();

    // Non-group content, org-cascaded: org-wide (NULL) or in the user's lineage.
    $nonGroup = $query->andConditionGroup()->isNull('gid');
    $org = $query->orConditionGroup()->isNull('org_unit');
    if ($lineage) {
      $org->condition('org_unit', $lineage, 'IN');
    }
    $nonGroup->condition($org);
    $scope->condition($nonGroup);

    // Group content is visible only to members of that group.
    if ($groupIds) {
      $scope->condition('gid', $groupIds, 'IN');
    }

    // Followed channels: subject nodes the caller resolved as followed-topic.
    if ($extraNodeIds) {
      $scope->condition('entity_id', $extraNodeIds, 'IN');
    }

    $query->condition($scope);
  }

  /**
   * The org-unit term id targeted by a node, or NULL for org-wide.
   */
  private function nodeOrgUnit(NodeInterface $node): ?int {
    if ($node->hasField('field_org_unit') && !$node->get('field_org_unit')->isEmpty()) {
      return (int) $node->get('field_org_unit')->target_id;
    }
    return NULL;
  }

  /**
   * The group a node belongs to (group_node relationship), or NULL.
   */
  private function nodeGroupId(int $nid): ?int {
    if (!$this->database->schema()->tableExists('group_relationship_field_data')) {
      return NULL;
    }
    $gid = $this->database->select('group_relationship_field_data', 'g')
      ->fields('g', ['gid'])
      ->condition('entity_id', $nid)
      ->condition('plugin_id', 'group_node:%', 'LIKE')
      ->range(0, 1)
      ->execute()
      ->fetchField();
    return $gid === FALSE ? NULL : (int) $gid;
  }

}
