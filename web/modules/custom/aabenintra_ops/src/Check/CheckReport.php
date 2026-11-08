<?php

declare(strict_types=1);

namespace Drupal\aabenintra_ops\Check;

/**
 * Ordered collection of check results with the exit-code policy.
 *
 * Exit code 0 = every check ok, skipped or warn; 1 = at least one fail.
 * In strict mode a warn also yields 1.
 */
final class CheckReport {

  /**
   * Results in run order.
   *
   * @var list<array{id: string, label: string, result: CheckResult}>
   */
  private array $entries = [];

  /**
   * {@inheritdoc}
   */
  public function add(string $id, string $label, CheckResult $result): void {
    $this->entries[] = ['id' => $id, 'label' => $label, 'result' => $result];
  }

  /**
   * All entries.
   *
   * @return list<array{id: string, label: string, result: CheckResult}>
   *   Results in run order.
   */
  public function entries(): array {
    return $this->entries;
  }

  /**
   * {@inheritdoc}
   */
  public function count(): int {
    return count($this->entries);
  }

  /**
   * {@inheritdoc}
   */
  public function worstStatus(): CheckStatus {
    $worst = CheckStatus::Skipped;
    foreach ($this->entries as $entry) {
      if ($entry['result']->status->severity() > $worst->severity()) {
        $worst = $entry['result']->status;
      }
    }
    return $worst;
  }

  /**
   * {@inheritdoc}
   */
  public function hasFailures(): bool {
    return $this->worstStatus() === CheckStatus::Fail;
  }

  /**
   * {@inheritdoc}
   */
  public function hasWarnings(): bool {
    foreach ($this->entries as $entry) {
      if ($entry['result']->isWarning()) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function exitCode(bool $strict = FALSE): int {
    if ($this->hasFailures()) {
      return 1;
    }
    if ($strict && $this->hasWarnings()) {
      return 1;
    }
    return 0;
  }

  /**
   * Number of results per status value.
   *
   * @return array<string, int>
   *   Keyed by CheckStatus value.
   */
  public function counts(): array {
    $counts = [];
    foreach (CheckStatus::cases() as $status) {
      $counts[$status->value] = 0;
    }
    foreach ($this->entries as $entry) {
      $counts[$entry['result']->status->value]++;
    }
    return $counts;
  }

  /**
   * Rows for Drush output formatters (table/json/yaml/csv).
   *
   * @return array<string, array{check: string, label: string, status: string, message: string, details: string}>
   *   Keyed by check id.
   */
  public function toRows(): array {
    $rows = [];
    foreach ($this->entries as $entry) {
      $rows[$entry['id']] = [
        'check' => $entry['id'],
        'label' => $entry['label'],
        'status' => $entry['result']->status->value,
        'message' => $entry['result']->message,
        'details' => $entry['result']->detailsLine(),
      ];
    }
    return $rows;
  }

  /**
   * One-line summary, e.g. "12 checks: 10 ok, 1 warn, 0 fail, 1 skipped".
   */
  public function summary(): string {
    $c = $this->counts();
    return sprintf('%d checks: %d ok, %d warn, %d fail, %d skipped', $this->count(), $c['ok'], $c['warn'], $c['fail'], $c['skipped']);
  }

}
