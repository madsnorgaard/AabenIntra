<?php

declare(strict_types=1);

namespace Drupal\aabenintra_ops\Check;

/**
 * Outcome of a single health check.
 */
enum CheckStatus: string {

  case Ok = 'ok';
  case Warn = 'warn';
  case Fail = 'fail';
  case Skipped = 'skipped';

  /**
   * Severity used to pick the worst status of a report (higher is worse).
   */
  public function severity(): int {
    return match ($this) {
      self::Skipped => 0,
      self::Ok => 1,
      self::Warn => 2,
      self::Fail => 3,
    };
  }

}
