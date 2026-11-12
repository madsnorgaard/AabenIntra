<?php

declare(strict_types=1);

namespace Drupal\aabenintra_gdpr\Controller;

use Drupal\aabenintra_gdpr\ExportRequestStorage;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystemInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves a finished export to its owner, once.
 */
final class ExportDownloadController extends ControllerBase {

  public function __construct(
    private readonly ExportRequestStorage $requests,
    private readonly FileSystemInterface $fileSystem,
    private readonly TimeInterface $time,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aabenintra_gdpr.requests'),
      $container->get('file_system'),
      $container->get('datetime.time'),
    );
  }

  public function download(string $token): Response {
    $row = $this->requests->loadByToken($token);
    if ($row === NULL) {
      // Unknown token: do not reveal whether it ever existed.
      throw new NotFoundHttpException();
    }
    if ((int) $row['uid'] !== (int) $this->currentUser()->id()) {
      throw new AccessDeniedHttpException('This export belongs to another account.');
    }
    if ($row['status'] !== ExportRequestStorage::STATUS_READY) {
      throw new GoneHttpException('This download link has already been used or has expired.');
    }
    if ((int) $row['expires'] > 0 && (int) $row['expires'] < $this->time->getRequestTime()) {
      $this->requests->expireStale();
      throw new GoneHttpException('This download link has expired.');
    }
    $path = $this->fileSystem->realpath((string) $row['uri']);
    if ($path === FALSE || !is_file($path)) {
      $this->requests->markFailed((int) $row['id'], 'Export file missing at download time.');
      throw new NotFoundHttpException();
    }
    // Invalidate before streaming so a second request cannot race the first.
    $this->requests->markDownloaded((int) $row['id']);

    $response = new BinaryFileResponse($path, 200, [
      'Content-Type' => 'application/json; charset=utf-8',
      'Cache-Control' => 'private, no-store',
      'X-Content-Type-Options' => 'nosniff',
    ], FALSE);
    $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'personal-data-export-' . $row['uid'] . '.json');
    return $response;
  }

}
