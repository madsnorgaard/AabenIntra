<?php

declare(strict_types=1);

namespace Drupal\aabenintra_audit\EventSubscriber;

use Drupal\aabenintra_audit\AuditEvents;
use Drupal\aabenintra_audit\AuditRecorder;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Records use of one-time password-reset links.
 *
 * - user.reset (the link from the mail is opened): password_reset_link_opened.
 * - user.reset.login (the "Log in" button on the reset page): on success core
 *   logs the user in (login_success with method=password_reset follows from
 *   hook_user_login); when the link is expired or forged core redirects back
 *   to /user/password and we record password_reset_link_rejected.
 *
 * The hash and timestamp from the URL are never stored.
 */
final class PasswordResetSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly AuditRecorder $recorder,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // After routing (priority 32) so _route is resolved.
      KernelEvents::REQUEST => ['onRequest', 30],
      KernelEvents::RESPONSE => ['onResponse', 0],
    ];
  }

  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $request = $event->getRequest();
    if ($request->attributes->get('_route') !== 'user.reset') {
      return;
    }
    $uid = (int) $request->attributes->get('uid', 0);
    $this->recorder->record(AuditEvents::PASSWORD_RESET_LINK_OPENED, $uid);
  }

  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $request = $event->getRequest();
    if ($request->attributes->get('_route') !== 'user.reset.login') {
      return;
    }
    $uid = (int) $request->attributes->get('uid', 0);
    if ((int) $this->currentUser->id() === $uid && $uid > 0) {
      $this->recorder->record(AuditEvents::PASSWORD_RESET_LINK_USED, $uid);
      return;
    }
    $response = $event->getResponse();
    $reason = $response instanceof RedirectResponse ? 'expired_or_invalid' : 'not_logged_in';
    $this->recorder->record(AuditEvents::PASSWORD_RESET_LINK_REJECTED, $uid, ['reason' => $reason]);
  }

}
