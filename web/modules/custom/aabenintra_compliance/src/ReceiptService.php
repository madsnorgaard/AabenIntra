<?php

declare(strict_types=1);

namespace Drupal\aabenintra_compliance;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Records and queries mandatory-read acknowledgements.
 */
final class ReceiptService {

  private const TABLE = 'aabenintra_read_receipt';

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Records that a user acknowledged a node (idempotent).
   */
  public function record(int $nid, int $uid, string $langcode): void {
    if ($uid === 0) {
      return;
    }
    $this->database->merge(self::TABLE)
      ->keys(['nid' => $nid, 'uid' => $uid, 'langcode' => $langcode])
      ->fields(['created' => $this->time->getRequestTime()])
      ->execute();
  }

  /**
   * Whether a user has acknowledged a node.
   */
  public function hasRead(int $nid, int $uid, string $langcode): bool {
    if ($uid === 0) {
      return TRUE;
    }
    return (bool) $this->database->select(self::TABLE, 'r')
      ->fields('r', ['id'])
      ->condition('nid', $nid)
      ->condition('uid', $uid)
      ->condition('langcode', $langcode)
      ->range(0, 1)
      ->execute()
      ->fetchField();
  }

  /**
   * Number of acknowledgements for a node (any language).
   */
  public function countForNode(int $nid): int {
    return (int) $this->database->select(self::TABLE, 'r')
      ->condition('nid', $nid)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Reader rows for a node: [uid => created].
   *
   * @return array<int,int>
   */
  public function readers(int $nid): array {
    return $this->database->select(self::TABLE, 'r')
      ->fields('r', ['uid', 'created'])
      ->condition('nid', $nid)
      ->orderBy('created', 'DESC')
      ->execute()
      ->fetchAllKeyed();
  }

}
