<?php

declare(strict_types=1);

namespace Drupal\aabenintra_gdpr\Collector;

/**
 * Holds every tagged collector and exposes the ones active on this site.
 */
final class CollectorManager {

  /**
   * @var list<\Drupal\aabenintra_gdpr\Collector\GdprDataCollectorInterface>
   */
  private array $collectors = [];

  /**
   * Service-collector callback (tag: aabenintra_gdpr.collector).
   */
  public function addCollector(GdprDataCollectorInterface $collector): void {
    $this->collectors[] = $collector;
  }

  /**
   * All registered collectors, whether or not their module is installed.
   *
   * @return list<\Drupal\aabenintra_gdpr\Collector\GdprDataCollectorInterface>
   */
  public function all(): array {
    return $this->collectors;
  }

  /**
   * Collectors whose owning module is installed, keyed by id.
   *
   * @return array<string,\Drupal\aabenintra_gdpr\Collector\GdprDataCollectorInterface>
   */
  public function active(): array {
    $active = [];
    foreach ($this->collectors as $collector) {
      if ($collector->applies()) {
        $active[$collector->id()] = $collector;
      }
    }
    return $active;
  }

}
