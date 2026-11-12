<?php

declare(strict_types=1);

namespace Drupal\aabenintra_ops\Check;

/**
 * Immutable result of one health check.
 *
 * Details are free-form key/value pairs for the operator (paths, counts,
 * module names). They must never contain secrets: the result is printed to
 * deploy logs.
 */
final class CheckResult {

  /**
   * Constructs a result.
   *
   * @param \Drupal\aabenintra_ops\Check\CheckStatus $status
   *   Outcome of the check.
   * @param string $message
   *   One sentence for the operator.
   * @param array<string, scalar|array<int|string, scalar>|null> $details
   *   Secret-free key/value details.
   */
  public function __construct(
    public readonly CheckStatus $status,
    public readonly string $message,
    public readonly array $details = [],
  ) {}

  /**
   * Passed.
   *
   * @param string $message
   *   One sentence for the operator.
   * @param array<string, scalar|array<int|string, scalar>|null> $details
   *   Secret-free key/value details.
   */
  public static function ok(string $message, array $details = []): self {
    return new self(CheckStatus::Ok, $message, $details);
  }

  /**
   * Degraded but serving.
   *
   * @param string $message
   *   One sentence for the operator.
   * @param array<string, scalar|array<int|string, scalar>|null> $details
   *   Secret-free key/value details.
   */
  public static function warn(string $message, array $details = []): self {
    return new self(CheckStatus::Warn, $message, $details);
  }

  /**
   * Broken; the command exits non-zero.
   *
   * @param string $message
   *   One sentence for the operator.
   * @param array<string, scalar|array<int|string, scalar>|null> $details
   *   Secret-free key/value details.
   */
  public static function fail(string $message, array $details = []): self {
    return new self(CheckStatus::Fail, $message, $details);
  }

  /**
   * Not applicable on this tenant.
   *
   * @param string $message
   *   One sentence for the operator.
   * @param array<string, scalar|array<int|string, scalar>|null> $details
   *   Secret-free key/value details.
   */
  public static function skipped(string $message, array $details = []): self {
    return new self(CheckStatus::Skipped, $message, $details);
  }

  /**
   * Whether the check failed.
   */
  public function isFailure(): bool {
    return $this->status === CheckStatus::Fail;
  }

  /**
   * Whether the check warned.
   */
  public function isWarning(): bool {
    return $this->status === CheckStatus::Warn;
  }

  /**
   * Renders the details as a single human-readable line.
   */
  public function detailsLine(): string {
    $parts = [];
    foreach ($this->details as $key => $value) {
      if (is_array($value)) {
        $value = implode(', ', array_map('strval', $value));
      }
      elseif (is_bool($value)) {
        $value = $value ? 'yes' : 'no';
      }
      elseif ($value === NULL) {
        $value = '-';
      }
      $parts[] = $key . '=' . $value;
    }
    return implode('; ', $parts);
  }

}
