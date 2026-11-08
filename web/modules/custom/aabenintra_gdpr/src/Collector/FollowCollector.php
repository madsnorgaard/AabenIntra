<?php

declare(strict_types=1);

namespace Drupal\aabenintra_gdpr\Collector;

/**
 * Channels (topics) and other things the subject follows.
 */
final class FollowCollector extends TableCollectorBase {

  public function id(): string {
    return 'follows';
  }

  public function label(): string {
    return 'Followed channels';
  }

  public function module(): string {
    return 'aabenintra_channels';
  }

  protected function table(): string {
    return 'aabenintra_follow';
  }

  protected function uidColumns(): array {
    return ['uid'];
  }

  protected function exportColumns(): array {
    return ['target_type', 'target_id', 'created'];
  }

}
