<?php

declare(strict_types=1);

namespace Drupal\aabenintra_gdpr\Collector;

use Drupal\aabenintra_gdpr\ErasureMode;
use Drupal\aabenintra_gdpr\ExportRequestStorage;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\user\UserInterface;

/**
 * The subject's own export requests (this module's ledger) and their files.
 */
final class ExportRequestCollector extends CollectorBase {

  public function __construct(
    ModuleHandlerInterface $moduleHandler,
    private readonly ExportRequestStorage $requests,
    private readonly FileSystemInterface $fileSystem,
  ) {
    parent::__construct($moduleHandler);
  }

  public function id(): string {
    return 'export_requests';
  }

  public function label(): string {
    return 'Data export requests';
  }

  public function module(): string {
    return 'aabenintra_gdpr';
  }

  public function count(UserInterface $subject): int {
    return $this->requests->countForUser((int) $subject->id());
  }

  public function collect(UserInterface $subject): array {
    $items = [];
    foreach ($this->requests->forUser((int) $subject->id(), 100) as $row) {
      $items[] = [
        'id' => (int) $row['id'],
        'status' => $row['status'],
        'created' => $this->iso($row['created']),
        'completed' => $this->iso($row['completed']),
        'downloaded' => $this->iso($row['downloaded']),
      ];
    }
    return $items;
  }

  public function erase(UserInterface $subject, ErasureMode $mode, bool $dryRun): int {
    $count = $this->count($subject);
    if ($dryRun || $count === 0) {
      return $count;
    }
    foreach ($this->requests->deleteForUser((int) $subject->id()) as $uri) {
      if (is_file($uri)) {
        $this->fileSystem->delete($uri);
      }
    }
    return $count;
  }

  public function erasureNote(ErasureMode $mode): string {
    return 'request rows and any remaining export files deleted';
  }

}
