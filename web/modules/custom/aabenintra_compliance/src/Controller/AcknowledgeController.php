<?php

declare(strict_types=1);

namespace Drupal\aabenintra_compliance\Controller;

use Drupal\aabenintra_compliance\ReceiptService;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Records a mandatory-read acknowledgement for the current user.
 */
final class AcknowledgeController extends ControllerBase {

  private const CSRF_KEY = 'aabenintra_acknowledge';

  public function __construct(
    private readonly ReceiptService $receipts,
    private readonly CsrfTokenGenerator $csrfToken,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aabenintra_compliance.receipts'),
      $container->get('csrf_token'),
    );
  }

  public function acknowledge(NodeInterface $node, Request $request): JsonResponse {
    if (!$this->csrfToken->validate((string) $request->headers->get('X-CSRF-Token', ''), self::CSRF_KEY)) {
      return new JsonResponse(['error' => 'Invalid CSRF token'], 403);
    }
    // Record against the node in the language the user is viewing.
    $langcode = $node->language()->getId();
    $this->receipts->record((int) $node->id(), (int) $this->currentUser()->id(), $langcode);
    return new JsonResponse(['ok' => TRUE, 'nid' => (int) $node->id()]);
  }

}
