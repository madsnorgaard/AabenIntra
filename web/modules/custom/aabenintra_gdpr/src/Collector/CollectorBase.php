<?php

declare(strict_types=1);

namespace Drupal\aabenintra_gdpr\Collector;

use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Shared plumbing for collectors: module gating and date formatting.
 */
abstract class CollectorBase implements GdprDataCollectorInterface {

  public function __construct(
    protected readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(): bool {
    return $this->moduleHandler->moduleExists($this->module());
  }

  /**
   * ISO 8601 (UTC) representation of a Unix timestamp, or NULL for 0/empty.
   */
  protected function iso(int|string|null $timestamp): ?string {
    $timestamp = (int) $timestamp;
    if ($timestamp <= 0) {
      return NULL;
    }
    return (new \DateTimeImmutable('@' . $timestamp))->format(\DateTimeInterface::ATOM);
  }

}
