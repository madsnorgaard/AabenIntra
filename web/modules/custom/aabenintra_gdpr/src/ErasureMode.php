<?php

declare(strict_types=1);

namespace Drupal\aabenintra_gdpr;

/**
 * How a data subject is erased.
 */
enum ErasureMode: string {

  /**
   * Keep the account row pseudonymised and reassign authored content.
   *
   * Mirrors core's "user_cancel_reassign" outcome for content, but the user
   * row itself survives (blocked, name/mail replaced, fields emptied) so that
   * foreign keys such as compliance receipts stay valid.
   */
  case Anonymise = 'anonymise';

  /**
   * Delete the account and everything it authored (core user_cancel_delete).
   */
  case Delete = 'delete';

  public static function fromOption(string $value): self {
    $value = strtolower(trim($value));
    return match ($value) {
      'anonymise', 'anonymize', 'anon' => self::Anonymise,
      'delete', 'cascade' => self::Delete,
      default => throw new \InvalidArgumentException(sprintf('Unknown erasure mode "%s"; use anonymise or delete.', $value)),
    };
  }

}
