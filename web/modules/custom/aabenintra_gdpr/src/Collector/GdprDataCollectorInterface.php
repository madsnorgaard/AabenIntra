<?php

declare(strict_types=1);

namespace Drupal\aabenintra_gdpr\Collector;

use Drupal\aabenintra_gdpr\ErasureMode;
use Drupal\user\UserInterface;

/**
 * One source of personal data, owned by one module.
 *
 * Register implementations with the "aabenintra_gdpr.collector" service tag.
 * A collector must not assume its owning module is installed: applies() is
 * consulted first and should check the module handler (and, for custom tables,
 * the schema) so the GDPR module never hard-depends on feature modules.
 */
interface GdprDataCollectorInterface {

  /**
   * Machine name used as the section key in the export document.
   */
  public function id(): string;

  /**
   * Human readable label.
   */
  public function label(): string;

  /**
   * Machine name of the module that owns the data.
   */
  public function module(): string;

  /**
   * Whether this collector can run on this site (owning module installed).
   */
  public function applies(): bool;

  /**
   * Number of records held about the subject.
   */
  public function count(UserInterface $subject): int;

  /**
   * The records held about the subject, in a JSON-serialisable shape.
   *
   * @return array<int|string,mixed>
   */
  public function collect(UserInterface $subject): array;

  /**
   * Erases (or anonymises) the subject's records.
   *
   * Runs before the account itself is touched, so the uid is still valid. In
   * dry-run mode nothing may change; the return value is the number of rows
   * that would be affected.
   *
   * @return int
   *   Rows deleted or rewritten.
   */
  public function erase(UserInterface $subject, ErasureMode $mode, bool $dryRun): int;

  /**
   * Short note on what erase() does per mode, for reports and docs.
   */
  public function erasureNote(ErasureMode $mode): string;

}
