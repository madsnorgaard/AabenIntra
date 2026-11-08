<?php

declare(strict_types=1);

namespace Drupal\aabenintra_audit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\Statement\FetchAs;

/**
 * Read access to the audit table.
 *
 * findByUid() is the integration point for data-subject exports (GDPR art.
 * 15/20): it returns every row about one user as plain arrays, with details
 * decoded, so a consumer needs no knowledge of the table.
 */
final class AuditRepository {

  public const TABLE = AuditRecorder::TABLE;

  /**
   * Filters understood by select(): event, uid, username, ip, from, to.
   *
   * @var string[]
   */
  public const FILTERS = ['event', 'uid', 'username', 'ip', 'from', 'to'];

  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * All audit rows about one user, newest first.
   *
   * Includes rows where the user is the subject (uid) and rows where their
   * account name was typed into the login form (username_attempted).
   *
   * @return array<int,array<string,mixed>>
   *   Rows with keys id, event, uid, username_attempted, ip, ip_anonymised,
   *   user_agent, session_hash, severity, details (array), created.
   */
  public function findByUid(int $uid, ?string $accountName = NULL): array {
    if ($uid <= 0) {
      return [];
    }
    $query = $this->database->select(self::TABLE, 'a')->fields('a');
    $or = $query->orConditionGroup()->condition('a.uid', $uid);
    if ($accountName !== NULL && $accountName !== '') {
      $or->condition('a.username_attempted', $accountName);
    }
    $query->condition($or)->orderBy('a.created', 'DESC')->orderBy('a.id', 'DESC');
    return array_map([self::class, 'decode'], $query->execute()->fetchAll(FetchAs::Associative));
  }

  /**
   * Base query for the admin UI and the CSV export.
   *
   * @param array<string,mixed> $filters
   *   Any of self::FILTERS. 'from'/'to' are unix timestamps, 'ip' is an exact
   *   address or a prefix ending in '*', 'username' matches both the account
   *   name of the subject uid and username_attempted.
   */
  public function select(array $filters = []): SelectInterface {
    $query = $this->database->select(self::TABLE, 'a')->fields('a');
    if (!empty($filters['event'])) {
      $query->condition('a.event', (array) $filters['event'], 'IN');
    }
    if (!empty($filters['uid'])) {
      $query->condition('a.uid', (int) $filters['uid']);
    }
    if (!empty($filters['username'])) {
      $name = (string) $filters['username'];
      $or = $query->orConditionGroup()->condition('a.username_attempted', $name);
      $uids = $this->database->select('users_field_data', 'u')
        ->fields('u', ['uid'])
        ->condition('u.name', $name)
        ->execute()
        ->fetchCol();
      if ($uids !== []) {
        $or->condition('a.uid', array_map('intval', $uids), 'IN');
      }
      $query->condition($or);
    }
    if (!empty($filters['ip'])) {
      $ip = trim((string) $filters['ip']);
      if (str_ends_with($ip, '*')) {
        $query->condition('a.ip', $this->database->escapeLike(rtrim($ip, '*')) . '%', 'LIKE');
      }
      else {
        $query->condition('a.ip', $ip);
      }
    }
    if (!empty($filters['from'])) {
      $query->condition('a.created', (int) $filters['from'], '>=');
    }
    if (!empty($filters['to'])) {
      $query->condition('a.created', (int) $filters['to'], '<=');
    }
    $query->orderBy('a.created', 'DESC')->orderBy('a.id', 'DESC');
    return $query;
  }

  /**
   * Number of login_success rows for a user from an exact IP since $since.
   */
  public function countLoginsFromIp(int $uid, string $ip, int $since): int {
    if ($uid <= 0 || $ip === '') {
      return 0;
    }
    return (int) $this->database->select(self::TABLE, 'a')
      ->condition('a.uid', $uid)
      ->condition('a.ip', $ip)
      ->condition('a.event', [AuditEvents::LOGIN_SUCCESS, AuditEvents::SSO_LOGIN], 'IN')
      ->condition('a.created', $since, '>=')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Number of login_success rows for a user at all (any IP) since $since.
   */
  public function countLogins(int $uid, int $since): int {
    if ($uid <= 0) {
      return 0;
    }
    return (int) $this->database->select(self::TABLE, 'a')
      ->condition('a.uid', $uid)
      ->condition('a.event', [AuditEvents::LOGIN_SUCCESS, AuditEvents::SSO_LOGIN], 'IN')
      ->condition('a.created', $since, '>=')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Number of failed / flood-blocked login rows from one IP since $since.
   */
  public function countFailedFromIp(string $ip, int $since): int {
    if ($ip === '') {
      return 0;
    }
    return (int) $this->database->select(self::TABLE, 'a')
      ->condition('a.ip', $ip)
      ->condition('a.event', [AuditEvents::LOGIN_FAILED, AuditEvents::LOGIN_BLOCKED_FLOOD], 'IN')
      ->condition('a.created', $since, '>=')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Decodes one raw row (details JSON -> array, ints cast).
   *
   * @param array<string,mixed> $row
   *
   * @return array<string,mixed>
   */
  public static function decode(array $row): array {
    $details = [];
    if (!empty($row['details']) && is_string($row['details'])) {
      $decoded = json_decode($row['details'], TRUE);
      $details = is_array($decoded) ? $decoded : [];
    }
    foreach (['id', 'uid', 'ip_anonymised', 'severity', 'created'] as $int) {
      if (isset($row[$int])) {
        $row[$int] = (int) $row[$int];
      }
    }
    $row['details'] = $details;
    return $row;
  }

}
