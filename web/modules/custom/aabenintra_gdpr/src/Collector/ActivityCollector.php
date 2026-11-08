<?php

declare(strict_types=1);

namespace Drupal\aabenintra_gdpr\Collector;

/**
 * Activity feed events where the subject is the actor or the target.
 */
final class ActivityCollector extends TableCollectorBase {

  public function id(): string {
    return 'activity';
  }

  public function label(): string {
    return 'Activity feed events';
  }

  public function module(): string {
    return 'aabenintra_activity';
  }

  protected function table(): string {
    return 'aabenintra_activity';
  }

  protected function uidColumns(): array {
    return ['actor_uid', 'target_uid'];
  }

  protected function exportColumns(): array {
    return ['actor_uid', 'verb', 'entity_type', 'entity_id', 'ref_id', 'target_uid', 'bundle', 'langcode', 'created'];
  }

}
