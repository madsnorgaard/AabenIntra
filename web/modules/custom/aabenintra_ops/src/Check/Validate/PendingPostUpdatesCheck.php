<?php

declare(strict_types=1);

namespace Drupal\aabenintra_ops\Check\Validate;

use Drupal\aabenintra_ops\Check\CheckResult;
use Drupal\aabenintra_ops\Check\HealthCheckInterface;
use Drupal\Core\Update\UpdateRegistry;

/**
 * No hook_post_update_NAME() may be pending.
 */
final class PendingPostUpdatesCheck implements HealthCheckInterface {

  public function __construct(
    private readonly UpdateRegistry $postUpdateRegistry,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'post_updates';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'No pending post-updates';
  }

  /**
   * {@inheritdoc}
   */
  public function groups(): array {
    return [self::GROUP_VALIDATE];
  }

  /**
   * {@inheritdoc}
   */
  public function run(): CheckResult {
    $pending = [];
    foreach ($this->postUpdateRegistry->getPendingUpdateInformation() as $extension => $info) {
      foreach (array_keys($info['pending'] ?? []) as $name) {
        $pending[] = $extension . '_post_update_' . $name;
      }
    }
    if ($pending !== []) {
      return CheckResult::fail(sprintf('%d pending post-update(s); run drush updb.', count($pending)), ['pending' => $pending]);
    }
    return CheckResult::ok('No pending post-updates.');
  }

}
