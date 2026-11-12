<?php

declare(strict_types=1);

namespace Drupal\aabenintra_ops\Check\Validate;

use Drupal\aabenintra_ops\Check\CheckResult;
use Drupal\aabenintra_ops\Check\HealthCheckInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Search API indexes are fully indexed and their servers reachable.
 *
 * Skipped while search_api is not part of the tenant. The module is reached
 * only through the entity type manager and method_exists() so this module has
 * no compile-time dependency on search_api.
 */
final class SearchIndexCheck implements HealthCheckInterface {

  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'search_index';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Search index status';
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
    $entityTypeId = 'search_api_index';
    if (!$this->moduleHandler->moduleExists('search_api') || !$this->entityTypeManager->hasDefinition($entityTypeId)) {
      return CheckResult::skipped('search_api is not enabled.');
    }
    $definition = $this->entityTypeManager->getDefinition($entityTypeId);
    $indexes = $this->entityTypeManager->getStorage($definition->id())->loadMultiple();
    if ($indexes === []) {
      return CheckResult::warn('search_api is enabled but no index exists.');
    }
    $details = [];
    $problems = [];
    foreach ($indexes as $id => $index) {
      $id = (string) $id;
      if (!method_exists($index, 'getTrackerInstance') || !method_exists($index, 'isServerEnabled') || !method_exists($index, 'status')) {
        continue;
      }
      if (!$index->status()) {
        $details[$id] = 'disabled';
        continue;
      }
      if (!$index->isServerEnabled()) {
        $problems[] = $id . ': server missing or disabled';
        continue;
      }
      $tracker = $index->getTrackerInstance();
      if (!is_object($tracker) || !method_exists($tracker, 'getIndexedItemsCount') || !method_exists($tracker, 'getTotalItemsCount')) {
        continue;
      }
      $indexed = (int) $tracker->getIndexedItemsCount();
      $total = (int) $tracker->getTotalItemsCount();
      $details[$id] = $indexed . '/' . $total;
      if ($indexed < $total) {
        $problems[] = sprintf('%s: %d of %d items indexed', $id, $indexed, $total);
      }
    }
    if ($problems !== []) {
      return CheckResult::warn('Search index is not up to date: ' . implode('; ', $problems) . '.', $details);
    }
    return CheckResult::ok('All search indexes are fully indexed.', $details);
  }

}
