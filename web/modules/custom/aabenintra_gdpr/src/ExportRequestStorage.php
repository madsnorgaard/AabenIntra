<?php

declare(strict_types=1);

namespace Drupal\aabenintra_gdpr;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;

/**
 * CRUD for self-service export requests (table aabenintra_gdpr_request).
 */
final class ExportRequestStorage {

  public const TABLE = 'aabenintra_gdpr_request';

  public const STATUS_PENDING = 'pending';
  public const STATUS_PROCESSING = 'processing';
  public const STATUS_READY = 'ready';
  public const STATUS_DOWNLOADED = 'downloaded';
  public const STATUS_FAILED = 'failed';
  public const STATUS_EXPIRED = 'expired';

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Creates a pending request and returns its id.
   */
  public function create(int $uid): int {
    return (int) $this->database->insert(self::TABLE)
      ->fields([
        'uid' => $uid,
        'status' => self::STATUS_PENDING,
        'created' => $this->time->getRequestTime(),
      ])
      ->execute();
  }

  /**
   * Timestamp of the user's most recent request, or 0.
   */
  public function lastRequestTime(int $uid): int {
    return (int) $this->database->select(self::TABLE, 'r')
      ->fields('r', ['created'])
      ->condition('uid', $uid)
      ->orderBy('created', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();
  }

  /**
   * Loads one request row by id.
   *
   * @return array<string,mixed>|null
   */
  public function load(int $id): ?array {
    $row = $this->database->select(self::TABLE, 'r')
      ->fields('r')
      ->condition('id', $id)
      ->execute()
      ->fetchAssoc();
    return $row === FALSE ? NULL : $row;
  }

  /**
   * Loads a request by the clear-text download token.
   *
   * @return array<string,mixed>|null
   */
  public function loadByToken(string $token): ?array {
    $row = $this->database->select(self::TABLE, 'r')
      ->fields('r')
      ->condition('token_hash', self::hashToken($token))
      ->execute()
      ->fetchAssoc();
    return $row === FALSE ? NULL : $row;
  }

  /**
   * Requests of one user, newest first.
   *
   * @return list<array<string,mixed>>
   */
  public function forUser(int $uid, int $limit = 20): array {
    return $this->database->select(self::TABLE, 'r')
      ->fields('r', ['id', 'status', 'message', 'created', 'completed', 'downloaded', 'expires'])
      ->condition('uid', $uid)
      ->orderBy('created', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll(FetchAs::Associative);
  }

  /**
   * All requests, newest first (admin listing).
   *
   * @return list<array<string,mixed>>
   */
  public function all(int $limit = 200): array {
    return $this->database->select(self::TABLE, 'r')
      ->fields('r', ['id', 'uid', 'status', 'message', 'created', 'completed', 'downloaded', 'expires'])
      ->orderBy('created', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll(FetchAs::Associative);
  }

  public function markProcessing(int $id): void {
    $this->database->update(self::TABLE)
      ->fields(['status' => self::STATUS_PROCESSING])
      ->condition('id', $id)
      ->execute();
  }

  /**
   * Marks a request ready, storing the file URI and the hashed token.
   */
  public function markReady(int $id, string $uri, string $token, int $expires): void {
    $this->database->update(self::TABLE)
      ->fields([
        'status' => self::STATUS_READY,
        'uri' => $uri,
        'token_hash' => self::hashToken($token),
        'completed' => $this->time->getRequestTime(),
        'expires' => $expires,
        'message' => '',
      ])
      ->condition('id', $id)
      ->execute();
  }

  public function markFailed(int $id, string $message): void {
    $this->database->update(self::TABLE)
      ->fields([
        'status' => self::STATUS_FAILED,
        'completed' => $this->time->getRequestTime(),
        'message' => mb_substr($message, 0, 255),
      ])
      ->condition('id', $id)
      ->execute();
  }

  /**
   * Marks a request downloaded; the token is invalidated at the same time.
   */
  public function markDownloaded(int $id): void {
    $this->database->update(self::TABLE)
      ->fields([
        'status' => self::STATUS_DOWNLOADED,
        'downloaded' => $this->time->getRequestTime(),
        'token_hash' => '',
      ])
      ->condition('id', $id)
      ->execute();
  }

  /**
   * Expires ready requests whose download window has passed.
   *
   * @return int
   *   Rows updated.
   */
  public function expireStale(): int {
    return $this->database->update(self::TABLE)
      ->fields(['status' => self::STATUS_EXPIRED, 'token_hash' => '', 'uri' => ''])
      ->condition('status', self::STATUS_READY)
      ->condition('expires', $this->time->getRequestTime(), '<')
      ->execute();
  }

  /**
   * Deletes every request row of a user; returns the file URIs they held.
   *
   * @return list<string>
   */
  public function deleteForUser(int $uid): array {
    $uris = $this->database->select(self::TABLE, 'r')
      ->fields('r', ['uri'])
      ->condition('uid', $uid)
      ->condition('uri', '', '<>')
      ->execute()
      ->fetchCol();
    $this->database->delete(self::TABLE)->condition('uid', $uid)->execute();
    return array_values(array_filter(array_map('strval', $uris)));
  }

  public function countForUser(int $uid): int {
    return (int) $this->database->select(self::TABLE, 'r')
      ->condition('uid', $uid)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * URL-safe random token (43 chars).
   */
  public static function generateToken(): string {
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
  }

  public static function hashToken(string $token): string {
    return hash('sha256', $token);
  }

}
