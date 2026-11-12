<?php

declare(strict_types=1);

namespace Drupal\aabenintra_ops\Check\Validate;

use Drupal\aabenintra_ops\Check\CheckResult;
use Drupal\aabenintra_ops\Check\HealthCheckInterface;
use Drupal\Core\Site\Settings;

/**
 * Exactly one valid trusted host pattern is configured.
 *
 * The production settings.php appends getenv('TRUSTED_HOSTS') to the
 * patterns, so a missing TRUSTED_HOSTS variable yields a single `false`
 * entry: that is a fail, not "one pattern". More than one pattern is allowed
 * but warns, because a tenant container serves exactly one host.
 */
final class TrustedHostsCheck implements HealthCheckInterface {

  public function __construct(
    private readonly Settings $settings,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'trusted_hosts';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Trusted host patterns';
  }

  /**
   * {@inheritdoc}
   */
  public function groups(): array {
    return [self::GROUP_VALIDATE, self::GROUP_ISOLATION];
  }

  /**
   * {@inheritdoc}
   */
  public function run(): CheckResult {
    $raw = $this->settings->get('trusted_host_patterns', []);
    $patterns = [];
    $invalid = [];
    foreach (is_array($raw) ? $raw : [] as $pattern) {
      if (!is_string($pattern) || trim($pattern) === '') {
        $invalid[] = 'empty';
        continue;
      }
      if (@preg_match('{' . $pattern . '}i', 'example.invalid') === FALSE) {
        $invalid[] = $pattern;
        continue;
      }
      $patterns[] = $pattern;
    }
    if ($invalid !== []) {
      return CheckResult::fail(
        'trusted_host_patterns contains an empty or invalid entry (TRUSTED_HOSTS unset in .env?).',
        ['invalid' => $invalid, 'valid' => $patterns],
      );
    }
    if ($patterns === []) {
      return CheckResult::fail('trusted_host_patterns is empty; Drupal would accept any Host header.');
    }
    if (count($patterns) > 1) {
      return CheckResult::warn(sprintf('%d trusted host patterns; a tenant container should serve a single host.', count($patterns)), ['patterns' => $patterns]);
    }
    return CheckResult::ok('One trusted host pattern is configured.', ['patterns' => $patterns]);
  }

}
