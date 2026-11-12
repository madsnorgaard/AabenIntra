<?php

declare(strict_types=1);

namespace Drupal\aabenintra_audit;

use Drupal\aabenintra_audit\Event\AuditEvent;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Optional suspicious-login alerting (off by default).
 *
 * Two simple rules, evaluated on every recorded audit event:
 *  - new_ip: a successful (local or SSO) login for a user from an IP they have
 *    not logged in from during the last alerts.new_ip_days. Users with no
 *    prior login in that window (first login, returning after a long break)
 *    do not alert, otherwise every onboarding would.
 *  - failed_burst: the alerts.failed_threshold-th failed or flood-blocked
 *    attempt from one IP within alerts.failed_window seconds. Alerts once,
 *    exactly when the threshold is reached, so a brute-force run produces
 *    one mail per window instead of hundreds.
 *
 * Each alert sends one mail (hook_mail key suspicious_login) to alerts.mail,
 * logs a warning and records an alert_sent audit row.
 */
final class SuspiciousLoginDetector implements EventSubscriberInterface {

  public function __construct(
    private readonly AuditRepository $repository,
    private readonly AuditRecorder $recorder,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly MailManagerInterface $mailManager,
    private readonly LanguageManagerInterface $languageManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [AuditEvent::NAME => 'onAuditEvent'];
  }

  public function onAuditEvent(AuditEvent $event): void {
    $config = $this->configFactory->get('aabenintra_audit.settings');
    if (!$config->get('alerts.enabled')) {
      return;
    }
    $reason = $this->evaluate($event);
    if ($reason === NULL) {
      return;
    }
    $to = trim((string) $config->get('alerts.mail'));
    $context = [
      'rule' => $reason,
      'trigger_event' => $event->event,
      'trigger_id' => $event->id,
      'uid' => $event->uid,
      'ip' => $event->ip,
    ];
    $this->logger->warning('Suspicious login activity (@rule): event @event for uid @uid from @ip.', [
      '@rule' => $reason,
      '@event' => $event->event,
      '@uid' => $event->uid,
      '@ip' => $event->ip,
    ]);
    $sent = FALSE;
    if ($to !== '') {
      $result = $this->mailManager->mail(
        'aabenintra_audit',
        'suspicious_login',
        $to,
        $this->languageManager->getDefaultLanguage()->getId(),
        ['context' => $context + ['username_attempted' => $event->usernameAttempted, 'created' => $event->created]],
      );
      $sent = !empty($result['result']);
    }
    $this->recorder->record(AuditEvents::ALERT_SENT, $event->uid, $context + ['mail_sent' => $sent]);
  }

  /**
   * Returns the rule name that fired, or NULL. Public so tests can call it.
   */
  public function evaluate(AuditEvent $event): ?string {
    $config = $this->configFactory->get('aabenintra_audit.settings');
    $now = $this->time->getRequestTime();

    if (in_array($event->event, [AuditEvents::LOGIN_SUCCESS, AuditEvents::SSO_LOGIN], TRUE) && $event->uid > 0 && $event->ip !== '') {
      $since = $now - max(1, (int) $config->get('alerts.new_ip_days')) * 86400;
      // The row for this very login is already written, hence "> 1" / "> 0".
      if ($this->repository->countLoginsFromIp($event->uid, $event->ip, $since) <= 1
        && $this->repository->countLogins($event->uid, $since) > 1) {
        return 'new_ip';
      }
      return NULL;
    }

    if (in_array($event->event, [AuditEvents::LOGIN_FAILED, AuditEvents::LOGIN_BLOCKED_FLOOD], TRUE) && $event->ip !== '') {
      $threshold = max(1, (int) $config->get('alerts.failed_threshold'));
      $since = $now - max(60, (int) $config->get('alerts.failed_window'));
      if ($this->repository->countFailedFromIp($event->ip, $since) === $threshold) {
        return 'failed_burst';
      }
    }
    return NULL;
  }

}
