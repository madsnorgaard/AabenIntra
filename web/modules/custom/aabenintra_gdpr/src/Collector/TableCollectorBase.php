<?php

declare(strict_types=1);

namespace Drupal\aabenintra_gdpr\Collector;

use Drupal\aabenintra_gdpr\ErasureMode;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\user\UserInterface;

/**
 * Collector over a module-private ledger table with one or more uid columns.
 *
 * Rows are personal data only while they point at a real person, so the
 * default erasure in both modes is to delete them. Subclasses override
 * erase()/erasureNote() where a legal basis for keeping rows exists.
 */
abstract class TableCollectorBase extends CollectorBase {

  public function __construct(
    ModuleHandlerInterface $moduleHandler,
    protected readonly Connection $database,
  ) {
    parent::__construct($moduleHandler);
  }

  /**
   * The ledger table.
   */
  abstract protected function table(): string;

  /**
   * Columns that hold a uid; a row matches when any of them equals the uid.
   *
   * @return list<string>
   */
  abstract protected function uidColumns(): array;

  /**
   * Columns to export, in order.
   *
   * @return list<string>
   */
  abstract protected function exportColumns(): array;

  /**
   * Columns holding Unix timestamps (exported as ISO 8601).
   *
   * @return list<string>
   */
  protected function timestampColumns(): array {
    return ['created'];
  }

  /**
   * {@inheritdoc}
   */
  public function applies(): bool {
    return parent::applies() && $this->database->schema()->tableExists($this->table());
  }

  public function count(UserInterface $subject): int {
    $query = $this->database->select($this->table(), 't');
    $this->whereSubject($query, (int) $subject->id());
    return (int) $query->countQuery()->execute()->fetchField();
  }

  public function collect(UserInterface $subject): array {
    $query = $this->database->select($this->table(), 't')->fields('t', $this->exportColumns());
    $this->whereSubject($query, (int) $subject->id());
    if (in_array('created', $this->exportColumns(), TRUE)) {
      $query->orderBy('created', 'ASC');
    }
    $items = [];
    foreach ($query->execute()->fetchAll(FetchAs::Associative) as $row) {
      foreach ($this->timestampColumns() as $column) {
        if (array_key_exists($column, $row)) {
          $row[$column] = $this->iso($row[$column]);
        }
      }
      foreach ($row as $key => $value) {
        if (is_numeric($value) && !in_array($key, $this->timestampColumns(), TRUE)) {
          $row[$key] = (int) $value;
        }
      }
      $items[] = $row;
    }
    return $items;
  }

  public function erase(UserInterface $subject, ErasureMode $mode, bool $dryRun): int {
    $count = $this->count($subject);
    if ($dryRun || $count === 0) {
      return $count;
    }
    $delete = $this->database->delete($this->table());
    $group = $delete->orConditionGroup();
    foreach ($this->uidColumns() as $column) {
      $group->condition($column, (int) $subject->id());
    }
    $delete->condition($group)->execute();
    return $count;
  }

  public function erasureNote(ErasureMode $mode): string {
    return 'rows deleted';
  }

  /**
   * Adds the "any uid column equals uid" condition.
   */
  protected function whereSubject(\Drupal\Core\Database\Query\SelectInterface $query, int $uid): void {
    $group = $query->orConditionGroup();
    foreach ($this->uidColumns() as $column) {
      $group->condition('t.' . $column, $uid);
    }
    $query->condition($group);
  }

}
