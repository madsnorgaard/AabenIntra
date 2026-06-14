<?php

declare(strict_types=1);

namespace Drupal\aabenintra_social\Controller;

use Drupal\aabenintra_social\Service\ReactionService;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Toggles a reaction on a node and returns the fresh counts.
 */
final class ReactionController extends ControllerBase {

  public function __construct(
    private readonly ReactionService $reactions,
    private readonly RequestStack $requestStack,
    private readonly CsrfTokenGenerator $csrfToken,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aabenintra_social.reaction'),
      $container->get('request_stack'),
      $container->get('csrf_token'),
    );
  }

  public function toggle(NodeInterface $node, string $reaction): JsonResponse {
    $request = $this->requestStack->getCurrentRequest();
    $token = (string) $request->headers->get('X-CSRF-Token', '');
    if (!$this->csrfToken->validate($token, 'aabenintra_social_react')) {
      throw new AccessDeniedHttpException('Invalid CSRF token.');
    }
    $account = $this->currentUser();
    $mine = $this->reactions->toggle($node, (int) $account->id(), $reaction);
    return new JsonResponse([
      'counts' => $this->reactions->counts($node),
      'mine' => $mine,
    ]);
  }

  /**
   * Access: authenticated users with permission to view the node.
   */
  public function access(AccountInterface $account, NodeInterface $node): \Drupal\Core\Access\AccessResultInterface {
    return \Drupal\Core\Access\AccessResult::allowedIf($account->isAuthenticated())
      ->andIf($node->access('view', $account, TRUE))
      ->cachePerUser()
      ->addCacheableDependency($node);
  }

}
