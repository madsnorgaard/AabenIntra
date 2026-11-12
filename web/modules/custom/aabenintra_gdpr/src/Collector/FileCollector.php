<?php

declare(strict_types=1);

namespace Drupal\aabenintra_gdpr\Collector;

use Drupal\aabenintra_gdpr\ErasureMode;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\user\UserInterface;

/**
 * Managed files the subject uploaded (attachments, images in content).
 *
 * Files are shared resources referenced from content, so they are reassigned
 * in both modes rather than deleted; files that lose their last usage become
 * temporary and core's file cron removes them.
 */
final class FileCollector extends CollectorBase {

  use ReassignTargetTrait;

  public function __construct(
    ModuleHandlerInterface $moduleHandler,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct($moduleHandler);
  }

  public function id(): string {
    return 'files';
  }

  public function label(): string {
    return 'Uploaded files';
  }

  public function module(): string {
    return 'file';
  }

  public function count(UserInterface $subject): int {
    return (int) $this->entityTypeManager->getStorage('file')->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $subject->id())
      ->count()
      ->execute();
  }

  public function collect(UserInterface $subject): array {
    $items = [];
    foreach ($this->load($subject) as $file) {
      $items[] = [
        'fid' => (int) $file->id(),
        'filename' => $file->getFilename(),
        'uri' => $file->getFileUri(),
        'mime' => $file->getMimeType(),
        'size' => (int) $file->getSize(),
        'created' => $this->iso($file->getCreatedTime()),
      ];
    }
    return $items;
  }

  public function erase(UserInterface $subject, ErasureMode $mode, bool $dryRun): int {
    $count = $this->count($subject);
    if ($dryRun || $count === 0) {
      return $count;
    }
    $target = $this->reassignTarget($this->configFactory, $this->entityTypeManager);
    foreach ($this->load($subject) as $file) {
      // A file may already have been deleted by the profile collector.
      if ($this->entityTypeManager->getStorage('file')->load($file->id()) === NULL) {
        continue;
      }
      $file->setOwnerId($target);
      $file->save();
    }
    return $count;
  }

  public function erasureNote(ErasureMode $mode): string {
    return 'kept; owner reassigned to reassign_uid (unused files are removed by file cron)';
  }

  /**
   * @return list<\Drupal\file\FileInterface>
   */
  private function load(UserInterface $subject): array {
    $storage = $this->entityTypeManager->getStorage('file');
    $fids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $subject->id())
      ->execute();
    return array_values($storage->loadMultiple($fids));
  }

}
