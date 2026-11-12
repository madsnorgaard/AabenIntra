<?php

declare(strict_types=1);

namespace Drupal\aabenintra_gdpr\Controller;

use Drupal\aabenintra_gdpr\ExportRequestStorage;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Queue\QueueFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Self-service export requests for the current user.
 *
 * POST /api/gdpr/export-requests queues a request (CSRF header required by the
 * route); GET lists the caller's requests. Both only ever act on the current
 * user - there is no way to request someone else's data here.
 */
final class ExportRequestController extends ControllerBase {

  public const QUEUE = 'aabenintra_gdpr_export';

  public function __construct(
    private readonly ExportRequestStorage $requests,
    private readonly QueueFactory $queueFactory,
    private readonly TimeInterface $time,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aabenintra_gdpr.requests'),
      $container->get('queue'),
      $container->get('datetime.time'),
    );
  }

  /**
   * POST: queue an export of the current user's data.
   */
  public function createRequest(): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    $limit_hours = (int) $this->config('aabenintra_gdpr.settings')->get('request_rate_limit_hours');
    $last = $this->requests->lastRequestTime($uid);
    $now = $this->time->getRequestTime();
    if ($limit_hours > 0 && $last > 0 && ($now - $last) < $limit_hours * 3600) {
      $retry = $last + $limit_hours * 3600;
      return new JsonResponse([
        'error' => 'rate_limited',
        'message' => sprintf('An export was already requested recently. Try again after %s.', gmdate(\DateTimeInterface::ATOM, $retry)),
        'retry_after' => gmdate(\DateTimeInterface::ATOM, $retry),
      ], 429, ['Retry-After' => (string) max(1, $retry - $now)]);
    }
    $id = $this->requests->create($uid);
    $this->queueFactory->get(self::QUEUE)->createItem(['request_id' => $id, 'uid' => $uid]);
    return new JsonResponse([
      'id' => $id,
      'status' => ExportRequestStorage::STATUS_PENDING,
      'message' => 'Your export has been queued. You will receive an e-mail with a download link when it is ready.',
    ], 202);
  }

  /**
   * GET: the current user's requests.
   */
  public function list(): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    $rows = [];
    foreach ($this->requests->forUser($uid) as $row) {
      $rows[] = [
        'id' => (int) $row['id'],
        'status' => $row['status'],
        'message' => $row['message'],
        'created' => $this->iso($row['created']),
        'completed' => $this->iso($row['completed']),
        'downloaded' => $this->iso($row['downloaded']),
        'expires' => $this->iso($row['expires']),
      ];
    }
    $response = new JsonResponse(['requests' => $rows]);
    $response->setPrivate();
    $response->headers->set('Cache-Control', 'private, no-store');
    return $response;
  }

  private function iso(mixed $timestamp): ?string {
    $timestamp = (int) $timestamp;
    return $timestamp > 0 ? gmdate(\DateTimeInterface::ATOM, $timestamp) : NULL;
  }

}
