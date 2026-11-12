<?php

declare(strict_types=1);

namespace Drupal\aabenintra_audit\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after an audit row has been written.
 *
 * Other modules (alerting, SIEM forwarding, a future OIDC/MitID module that
 * wants to react to its own sso_login rows) subscribe to AuditEvent::NAME.
 * The payload is read-only: the row already exists when this fires.
 */
final class AuditEvent extends Event {

  public const NAME = 'aabenintra_audit.recorded';

  /**
   * @param array<string,mixed> $details
   */
  public function __construct(
    public readonly int $id,
    public readonly string $event,
    public readonly int $uid,
    public readonly string $usernameAttempted,
    public readonly string $ip,
    public readonly int $severity,
    public readonly array $details,
    public readonly int $created,
  ) {}

}
