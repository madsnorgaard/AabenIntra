<?php

declare(strict_types=1);

namespace Drupal\aabenintra_theme\Controller;

use Drupal\aabenintra_theme\PreferencesService;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Read/write endpoint for per-user theme preferences.
 */
final class PreferencesController extends ControllerBase {

  private const CSRF_KEY = 'aabenintra_theme_prefs';

  public function __construct(
    private readonly PreferencesService $preferences,
    private readonly CsrfTokenGenerator $csrfToken,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aabenintra_theme.preferences'),
      $container->get('csrf_token'),
    );
  }

  /**
   * GET: the current user's effective preferences.
   */
  public function get(): JsonResponse {
    return new JsonResponse($this->preferences->getAll());
  }

  /**
   * PATCH: merge a preferences patch for the current user.
   */
  public function patch(Request $request): JsonResponse {
    $token = (string) $request->headers->get('X-CSRF-Token', '');
    if (!$this->csrfToken->validate($token, self::CSRF_KEY)) {
      return new JsonResponse(['error' => 'Invalid CSRF token'], 403);
    }
    $patch = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($patch)) {
      return new JsonResponse(['error' => 'Invalid payload'], 400);
    }
    $next = $this->preferences->setAll($patch);
    return new JsonResponse($next);
  }

}
