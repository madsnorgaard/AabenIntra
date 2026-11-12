<?php

declare(strict_types=1);

namespace Drupal\aabenintra_audit;

use Drupal\Core\Logger\RfcLogLevel;

/**
 * Catalogue of audit event types and their default severities.
 *
 * Event names are stable identifiers stored in the database; never rename an
 * existing constant value. Add new ones here so the admin filter and the docs
 * (docs/AUDIT-LOG.md) stay in sync.
 */
final class AuditEvents {

  public const LOGIN_SUCCESS = 'login_success';
  public const LOGIN_FAILED = 'login_failed';
  public const LOGIN_BLOCKED_FLOOD = 'login_blocked_flood';
  public const LOGOUT = 'logout';
  public const PASSWORD_RESET_REQUESTED = 'password_reset_requested';
  public const PASSWORD_RESET_LINK_OPENED = 'password_reset_link_opened';
  public const PASSWORD_RESET_LINK_USED = 'password_reset_link_used';
  public const PASSWORD_RESET_LINK_REJECTED = 'password_reset_link_rejected';
  public const PASSWORD_CHANGED = 'password_changed';
  public const MAIL_CHANGED = 'mail_changed';
  public const ROLES_CHANGED = 'roles_changed';
  public const ACCOUNT_BLOCKED = 'account_blocked';
  public const ACCOUNT_UNBLOCKED = 'account_unblocked';
  public const USER_CREATED = 'user_created';
  public const USER_DELETED = 'user_deleted';
  public const SSO_LOGIN = 'sso_login';
  public const SSO_LOGIN_FAILED = 'sso_login_failed';
  public const TOKEN_ISSUED = 'token_issued';
  public const TOKEN_REVOKED = 'token_revoked';
  public const ALERT_SENT = 'alert_sent';

  /**
   * Default severity per event (Drupal RfcLogLevel values).
   */
  public const SEVERITY = [
    self::LOGIN_SUCCESS => RfcLogLevel::INFO,
    self::LOGIN_FAILED => RfcLogLevel::NOTICE,
    self::LOGIN_BLOCKED_FLOOD => RfcLogLevel::WARNING,
    self::LOGOUT => RfcLogLevel::INFO,
    self::PASSWORD_RESET_REQUESTED => RfcLogLevel::NOTICE,
    self::PASSWORD_RESET_LINK_OPENED => RfcLogLevel::NOTICE,
    self::PASSWORD_RESET_LINK_USED => RfcLogLevel::NOTICE,
    self::PASSWORD_RESET_LINK_REJECTED => RfcLogLevel::WARNING,
    self::PASSWORD_CHANGED => RfcLogLevel::NOTICE,
    self::MAIL_CHANGED => RfcLogLevel::NOTICE,
    self::ROLES_CHANGED => RfcLogLevel::WARNING,
    self::ACCOUNT_BLOCKED => RfcLogLevel::WARNING,
    self::ACCOUNT_UNBLOCKED => RfcLogLevel::NOTICE,
    self::USER_CREATED => RfcLogLevel::NOTICE,
    self::USER_DELETED => RfcLogLevel::WARNING,
    self::SSO_LOGIN => RfcLogLevel::INFO,
    self::SSO_LOGIN_FAILED => RfcLogLevel::NOTICE,
    self::TOKEN_ISSUED => RfcLogLevel::NOTICE,
    self::TOKEN_REVOKED => RfcLogLevel::NOTICE,
    self::ALERT_SENT => RfcLogLevel::WARNING,
  ];

  /**
   * All known event names.
   *
   * @return string[]
   */
  public static function all(): array {
    return array_keys(self::SEVERITY);
  }

  public static function severity(string $event): int {
    return self::SEVERITY[$event] ?? RfcLogLevel::NOTICE;
  }

}
