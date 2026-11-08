<?php

declare(strict_types=1);

namespace Drupal\aabenintra_audit;

use Drupal\aabenintra_audit\Event\AuditEvent;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Writes authentication audit events.
 *
 * One row per event. Request context (client IP, user agent, session hash) is
 * captured automatically from the current request; callers pass only the
 * event name, the subject uid and non-secret details.
 *
 * Public API for other modules:
 *  - record(): generic entry point.
 *  - recordExternalLogin(): for SSO providers (OIDC/Entra, MitID, SAML).
 *  - AuditEvent (aabenintra_audit.recorded) is dispatched after every write.
 */
final class AuditRecorder {

  public const TABLE = 'aabenintra_audit_event';

  /**
   * Detail keys that are dropped defensively: secrets never belong in a log.
   */
  private const FORBIDDEN_DETAIL_KEY = '/pass|pwd|secret|token|hash|cookie|authorization|otp|code_verifier/i';

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly RequestStack $requestStack,
    private readonly EventDispatcherInterface $eventDispatcher,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Records one audit event and returns the new row id.
   *
   * @param string $event
   *   One of the AuditEvents constants (free-form names are accepted so an
   *   external module can add its own, but keep them snake_case, max 64).
   * @param int $uid
   *   Subject user, 0 when unknown.
   * @param array<string,mixed> $details
   *   Event-specific context. Must not contain secrets; keys that look like
   *   passwords, tokens or hashes are removed. Stored as JSON.
   * @param string $usernameAttempted
   *   Name typed by the client, for failed logins / reset requests.
   * @param int|null $severity
   *   RfcLogLevel value; defaults to the catalogue value for the event.
   */
  public function record(string $event, int $uid = 0, array $details = [], string $usernameAttempted = '', ?int $severity = NULL): int {
    $event = mb_substr($event, 0, 64);
    $severity ??= AuditEvents::severity($event);
    $details = $this->sanitiseDetails($details);
    $created = $this->time->getRequestTime();
    $request = $this->requestStack->getCurrentRequest();

    $ip = '';
    $userAgent = '';
    $sessionHash = '';
    if ($request !== NULL) {
      // Request::getClientIp() honours X-Forwarded-For only for trusted
      // proxies, which settings.php configures via reverse_proxy settings.
      $ip = (string) ($request->getClientIp() ?? '');
      $userAgent = mb_substr((string) $request->headers->get('User-Agent', ''), 0, 512);
      if ($request->hasSession()) {
        $sessionId = $request->getSession()->getId();
        if ($sessionId !== '') {
          $sessionHash = hash('sha256', $sessionId);
        }
      }
    }

    $id = (int) $this->database->insert(self::TABLE)
      ->fields([
        'event' => $event,
        'uid' => max(0, $uid),
        'username_attempted' => mb_substr($usernameAttempted, 0, 255),
        'ip' => mb_substr($ip, 0, 45),
        'ip_anonymised' => 0,
        'user_agent' => $userAgent,
        'session_hash' => $sessionHash,
        'severity' => $severity,
        'details' => $details === [] ? NULL : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'created' => $created,
      ])
      ->execute();

    $this->eventDispatcher->dispatch(
      new AuditEvent($id, $event, max(0, $uid), $usernameAttempted, $ip, $severity, $details, $created),
      AuditEvent::NAME,
    );
    return $id;
  }

  /**
   * Records a login that was authenticated by an external identity provider.
   *
   * Call this from the OIDC/SAML/MitID module's "user authenticated" hook or
   * event, after the local Drupal session has been created, e.g.:
   * @code
   * \Drupal::service('aabenintra_audit.recorder')
   *   ->recordExternalLogin('entra', $account->id(), ['subject' => $claims['sub'], 'tenant' => $claims['tid']]);
   * @endcode
   * Pass $success = FALSE (with uid 0 and the attempted identity in
   * $usernameAttempted) when the provider rejected the user or the claims
   * did not map to a local account.
   *
   * @param array<string,mixed> $details
   *   Provider-specific, non-secret context (subject id, tenant, acr, amr).
   *   Never pass id_tokens, access tokens, nonces or the authorization code.
   */
  public function recordExternalLogin(string $provider, int $uid, array $details = [], bool $success = TRUE, string $usernameAttempted = ''): int {
    $details = ['provider' => $provider, 'method' => 'sso'] + $details;
    return $this->record($success ? AuditEvents::SSO_LOGIN : AuditEvents::SSO_LOGIN_FAILED, $uid, $details, $usernameAttempted);
  }

  /**
   * Records that an API token was issued or revoked for a user.
   *
   * Reserved for future JSON:API / integration tokens (see
   * docs/SESSION-POLICY.md). Pass a token *label* or truncated fingerprint,
   * never the token itself.
   *
   * @param array<string,mixed> $details
   */
  public function recordToken(int $uid, string $label, bool $issued = TRUE, array $details = []): int {
    return $this->record($issued ? AuditEvents::TOKEN_ISSUED : AuditEvents::TOKEN_REVOKED, $uid, ['label' => $label] + $details);
  }

  /**
   * Removes keys that look like credentials and clamps nested depth/size.
   *
   * @param array<int|string,mixed> $details
   *
   * @return array<int|string,mixed>
   */
  private function sanitiseDetails(array $details, int $depth = 0): array {
    $clean = [];
    foreach ($details as $key => $value) {
      if (preg_match(self::FORBIDDEN_DETAIL_KEY, (string) $key) === 1) {
        $this->logger->debug('Dropped audit detail key @key (looks like a secret).', ['@key' => $key]);
        continue;
      }
      if (is_array($value)) {
        $clean[$key] = $depth < 3 ? $this->sanitiseDetails($value, $depth + 1) : '[...]';
      }
      elseif (is_scalar($value) || $value === NULL) {
        $clean[$key] = is_string($value) ? mb_substr($value, 0, 1000) : $value;
      }
      elseif ($value instanceof \Stringable) {
        $clean[$key] = mb_substr((string) $value, 0, 1000);
      }
    }
    return $clean;
  }

}
