<?php

declare(strict_types=1);

namespace Drupal\aabenintra_gdpr\Collector;

/**
 * Reactions (like, celebrate, ...) the subject placed on content.
 */
final class ReactionCollector extends TableCollectorBase {

  public function id(): string {
    return 'reactions';
  }

  public function label(): string {
    return 'Reactions';
  }

  public function module(): string {
    return 'aabenintra_social';
  }

  protected function table(): string {
    return 'aabenintra_reaction';
  }

  protected function uidColumns(): array {
    return ['uid'];
  }

  protected function exportColumns(): array {
    return ['entity_type', 'entity_id', 'reaction', 'created'];
  }

}
