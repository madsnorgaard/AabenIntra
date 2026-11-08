<?php

declare(strict_types=1);

namespace Drupal\aabenintra_ops\Check\Isolation;

use Drupal\aabenintra_ops\Check\CheckResult;
use Drupal\aabenintra_ops\Check\HealthCheckInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The session cookie is scoped to this tenant's host.
 *
 * Drupal names the session cookie SESS<hash> where the hash is derived from
 * the request host (plus base path), unless the container parameter
 * session.storage.options.cookie_domain pins it. Two tenants on different
 * hosts therefore never share a cookie name, and the browser scopes the
 * cookie to the host anyway. A cookie_domain that starts with a dot (a parent
 * domain such as .example.dk) would make the cookie travel to sibling tenants
 * and is reported as fail.
 */
final class SessionCookieCheck implements HealthCheckInterface {

  /**
   * Constructs the check.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   * @param array<string, mixed> $sessionOptions
   *   The session.storage.options container parameter.
   */
  public function __construct(
    private readonly RequestStack $requestStack,
    private readonly array $sessionOptions,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'session_cookie';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Session cookie scope';
  }

  /**
   * {@inheritdoc}
   */
  public function groups(): array {
    return [self::GROUP_ISOLATION];
  }

  /**
   * {@inheritdoc}
   */
  public function run(): CheckResult {
    $domain = $this->sessionOptions['cookie_domain'] ?? NULL;
    $request = $this->requestStack->getCurrentRequest();
    $host = $request !== NULL ? $request->getHost() : 'default';
    $details = [
      'host' => $host,
      'cookie_domain' => is_string($domain) && $domain !== '' ? $domain : '(derived from host)',
      'samesite' => (string) ($this->sessionOptions['cookie_samesite'] ?? 'default'),
    ];
    if (is_string($domain) && str_starts_with($domain, '.')) {
      return CheckResult::fail('session cookie_domain is a parent domain; the cookie would be sent to sibling tenants.', $details);
    }
    if (is_string($domain) && $domain !== '' && $host !== 'default' && strcasecmp($domain, $host) !== 0) {
      return CheckResult::warn('session cookie_domain differs from the request host.', $details);
    }
    return CheckResult::ok('Session cookie name and scope are derived from the tenant host.', $details);
  }

}
