<?php

declare(strict_types=1);

namespace Drupal\aabenintra_channels\Controller;

use Drupal\aabenintra_channels\FollowService;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Toggles following a channel (topic) and returns the fresh follow state.
 */
final class FollowController extends ControllerBase {

  public function __construct(
    private readonly FollowService $follow,
    private readonly RequestStack $requestStack,
    private readonly CsrfTokenGenerator $csrfToken,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aabenintra_channels.follow'),
      $container->get('request_stack'),
      $container->get('csrf_token'),
    );
  }

  public function toggle(TermInterface $taxonomy_term): JsonResponse {
    $request = $this->requestStack->getCurrentRequest();
    $token = (string) $request->headers->get('X-CSRF-Token', '');
    if (!$this->csrfToken->validate($token, 'aabenintra_channels_follow')) {
      throw new AccessDeniedHttpException('Invalid CSRF token.');
    }
    $tid = (int) $taxonomy_term->id();
    $following = $this->follow->toggle((int) $this->currentUser()->id(), FollowService::TYPE_TOPIC, $tid);
    return new JsonResponse([
      'following' => $following,
      'followers' => $this->follow->followerCount(FollowService::TYPE_TOPIC, $tid),
    ]);
  }

  /**
   * Access: any authenticated user may follow.
   */
  public function access(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIf($account->isAuthenticated())->cachePerUser();
  }

}
