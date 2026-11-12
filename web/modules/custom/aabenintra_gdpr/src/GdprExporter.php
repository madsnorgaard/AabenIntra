<?php

declare(strict_types=1);

namespace Drupal\aabenintra_gdpr;

use Drupal\aabenintra_gdpr\Collector\CollectorManager;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the machine-readable personal data export (GDPR Art. 15 and 20).
 */
final class GdprExporter {

  /**
   * Bump when the document shape changes incompatibly.
   */
  public const SCHEMA_VERSION = 1;

  /**
   * Directory (stream URI) exports are written to.
   */
  public const EXPORT_DIR = 'private://gdpr-exports';

  public function __construct(
    private readonly CollectorManager $collectors,
    private readonly FileSystemInterface $fileSystem,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Assembles the export document for a subject without writing anything.
   *
   * @return array{schema_version:int,exported_at:string,subject:array<string,mixed>,sections:array<string,array<string,mixed>>}
   */
  public function buildDocument(UserInterface $subject): array {
    $sections = [];
    foreach ($this->collectors->active() as $id => $collector) {
      $items = $collector->collect($subject);
      $sections[$id] = [
        'module' => $collector->module(),
        'label' => $collector->label(),
        'count' => count($items),
        'items' => array_values($items),
      ];
    }
    return [
      'schema_version' => self::SCHEMA_VERSION,
      'exported_at' => $this->iso($this->time->getRequestTime()),
      'subject' => [
        'uid' => (int) $subject->id(),
        'name' => $subject->getAccountName(),
        'mail' => $subject->getEmail(),
        'langcode' => $subject->getPreferredLangcode(),
        'created' => $this->iso((int) $subject->getCreatedTime()),
        'access' => $this->iso((int) $subject->getLastAccessedTime()),
        'roles' => array_values($subject->getRoles(TRUE)),
        'status' => $subject->isActive(),
      ],
      'sections' => $sections,
    ];
  }

  /**
   * JSON encodes a document (pretty, UTF-8 preserved).
   *
   * @param array<string,mixed> $document
   */
  public function encode(array $document): string {
    $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    return $json . "\n";
  }

  /**
   * Builds and writes the export, returning the file URI or path written.
   *
   * @param \Drupal\user\UserInterface $subject
   *   The data subject.
   * @param string|null $destination
   *   Optional explicit destination (local path or stream URI). Defaults to
   *   private://gdpr-exports/<uid>-<timestamp>.json.
   *
   * @throws \RuntimeException
   *   When the destination directory is not writable.
   */
  public function export(UserInterface $subject, ?string $destination = NULL): string {
    $json = $this->encode($this->buildDocument($subject));
    if ($destination === NULL || $destination === '') {
      $destination = self::EXPORT_DIR . '/' . $this->fileName((int) $subject->id());
    }
    $directory = $this->fileSystem->dirname($destination);
    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new \RuntimeException(sprintf('Cannot create or write export directory "%s". Is the private file system configured?', $directory));
    }
    $written = $this->fileSystem->saveData($json, $destination, FileExists::Replace);
    $this->logger->notice('Personal data export written for uid @uid.', ['@uid' => $subject->id()]);
    return $written;
  }

  /**
   * File name for a subject's export: "<uid>-<timestamp>.json".
   */
  public function fileName(int $uid): string {
    return sprintf('%d-%d.json', $uid, $this->time->getRequestTime());
  }

  /**
   * Configured retention in seconds (default 7 days).
   */
  public function retentionSeconds(): int {
    $days = (int) $this->configFactory->get('aabenintra_gdpr.settings')->get('retention_days');
    return max(1, $days > 0 ? $days : 7) * 86400;
  }

  /**
   * Deletes export files older than the retention period.
   *
   * @return int
   *   Number of files removed.
   */
  public function purgeExpired(): int {
    $real = $this->fileSystem->realpath(self::EXPORT_DIR);
    if ($real === FALSE || !is_dir($real)) {
      return 0;
    }
    $cutoff = $this->time->getRequestTime() - $this->retentionSeconds();
    $removed = 0;
    foreach ($this->fileSystem->scanDirectory(self::EXPORT_DIR, '/\.json$/', ['recurse' => FALSE]) as $file) {
      $mtime = @filemtime($file->uri);
      if ($mtime !== FALSE && $mtime < $cutoff) {
        $this->fileSystem->delete($file->uri);
        $removed++;
      }
    }
    return $removed;
  }

  private function iso(int $timestamp): ?string {
    if ($timestamp <= 0) {
      return NULL;
    }
    return (new \DateTimeImmutable('@' . $timestamp))->format(\DateTimeInterface::ATOM);
  }

}
