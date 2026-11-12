<?php

declare(strict_types=1);

namespace Drupal\aabenintra_gdpr;

/**
 * Deterministic replacement values for a pseudonymised account.
 *
 * Kept free of Drupal dependencies so it can be unit tested and reused by
 * collectors that need the same placeholder strings.
 */
final class Anonymizer {

  public const NAME_PREFIX = 'deleted-user-';
  public const MAIL_DOMAIN = 'invalid.invalid';

  /**
   * Account name replacement, e.g. "deleted-user-42".
   */
  public function name(int $uid): string {
    return self::NAME_PREFIX . $uid;
  }

  /**
   * E-mail replacement under the reserved .invalid TLD (RFC 2606).
   */
  public function mail(int $uid): string {
    return sprintf('deleted-%d@%s', $uid, self::MAIL_DOMAIN);
  }

  /**
   * Whether a name/mail pair already carries the placeholder shape.
   */
  public function isAnonymised(string $name, ?string $mail): bool {
    return str_starts_with($name, self::NAME_PREFIX)
      && $mail !== NULL
      && str_ends_with($mail, '@' . self::MAIL_DOMAIN);
  }

  /**
   * Salted, non-reversible subject reference for audit rows.
   *
   * The salt is the site's hash salt so the value is stable per site but
   * cannot be recomputed by someone who only has the uid.
   */
  public function subjectHash(int $uid, string $salt): string {
    return hash('sha256', $salt . ':' . $uid);
  }

  /**
   * A random password nobody knows (the account is also blocked).
   */
  public function randomPassword(): string {
    return bin2hex(random_bytes(32));
  }

}
