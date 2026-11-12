<?php

declare(strict_types=1);

namespace Drupal\aabenintra_ops\Check\Isolation;

use Drupal\aabenintra_ops\Check\CheckResult;
use Drupal\aabenintra_ops\Check\HealthCheckInterface;
use Drupal\Core\Site\Settings;

/**
 * Hash_salt is set, long and not the .env.example placeholder.
 *
 * The salt signs one-time login links, form tokens and private file URLs; two
 * tenants sharing a salt could forge each other's tokens. The value itself is
 * never printed.
 */
final class HashSaltCheck implements HealthCheckInterface {

  private const PLACEHOLDER_PREFIX = 'CHANGE_ME';

  public function __construct(
    private readonly Settings $settings,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'hash_salt';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Hash salt';
  }

  /**
   * {@inheritdoc}
   */
  public function groups(): array {
    return [self::GROUP_ISOLATION];
  }

  /**
   * {@inheritdoc}
   */
  public function run(): CheckResult {
    $salt = $this->settings->get('hash_salt');
    if (!is_string($salt) || $salt === '') {
      return CheckResult::fail('hash_salt is empty (HASH_SALT unset in .env?).');
    }
    if (str_starts_with($salt, self::PLACEHOLDER_PREFIX)) {
      return CheckResult::fail('hash_salt is still the .env.example placeholder.');
    }
    if (strlen($salt) < 32) {
      return CheckResult::warn('hash_salt is shorter than 32 characters; generate one with openssl rand -hex 32.', ['length' => strlen($salt)]);
    }
    return CheckResult::ok('hash_salt is set and not a placeholder.', ['length' => strlen($salt)]);
  }

}
