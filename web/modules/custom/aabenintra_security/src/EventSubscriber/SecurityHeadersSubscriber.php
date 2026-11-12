<?php

declare(strict_types=1);

namespace Drupal\aabenintra_security\EventSubscriber;

use Drupal\aabenintra_security\Csp\NonceService;
use Drupal\aabenintra_security\Csp\PolicyBuilder;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\PageCache\RequestPolicyInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\PageCache\ResponsePolicyInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds security response headers to every main-request response.
 *
 * Runs after core's FinishResponseSubscriber (priority 0), which already sets
 * X-Content-Type-Options: nosniff and X-Frame-Options: SAMEORIGIN on all main
 * responses; those are only filled in here when missing. Running after the
 * dynamic page cache stores its copy (priority 7) means the headers are
 * computed per response and never baked into the dynamic page cache. The
 * anonymous page cache does store full responses, so the settings config is
 * added as a cacheable dependency: saving the form invalidates every cached
 * page and the new policy applies at once.
 */
final class SecurityHeadersSubscriber implements EventSubscriberInterface {

  public const CONFIG_NAME = 'aabenintra_security.settings';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly PolicyBuilder $policyBuilder,
    private readonly NonceService $nonce,
    private readonly RequestPolicyInterface $pageCacheRequestPolicy,
    private readonly ResponsePolicyInterface $pageCacheResponsePolicy,
    private readonly KillSwitch $pageCacheKillSwitch,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::RESPONSE => ['onResponse', -10],
      KernelEvents::TERMINATE => ['onTerminate'],
    ];
  }

  /**
   * Sets the security headers on main-request responses.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $config = $this->configFactory->get(self::CONFIG_NAME);
    $response = $event->getResponse();
    if ($response instanceof CacheableResponseInterface) {
      $response->addCacheableDependency($config);
    }
    if (!$config->get('enabled')) {
      return;
    }
    $request = $event->getRequest();
    $headers = $response->headers;

    // Defence in depth: core sets both on main requests, keep them if absent.
    if (!$headers->has('X-Content-Type-Options')) {
      $headers->set('X-Content-Type-Options', 'nosniff');
    }
    if (!$headers->has('X-Frame-Options')) {
      $headers->set('X-Frame-Options', 'SAMEORIGIN');
    }

    // HSTS is only meaningful (and only honoured by browsers) over TLS. Behind
    // Traefik the scheme comes from X-Forwarded-Proto, which Request::isSecure()
    // reads because settings.php sets $settings['reverse_proxy'].
    if ($config->get('hsts.enabled') && $request->isSecure() && !$headers->has('Strict-Transport-Security')) {
      $headers->set('Strict-Transport-Security', self::hstsValue($config));
    }

    $referrer = trim((string) $config->get('referrer_policy'));
    if ($referrer !== '' && !$headers->has('Referrer-Policy')) {
      $headers->set('Referrer-Policy', $referrer);
    }

    $permissions = trim((string) $config->get('permissions_policy'));
    if ($permissions !== '' && !$headers->has('Permissions-Policy')) {
      $headers->set('Permissions-Policy', $permissions);
    }

    if ($config->get('csp.enabled')) {
      $this->setContentSecurityPolicy($config, $request, $response);
    }
  }

  /**
   * Drops the request nonce so a long-running runtime cannot reuse it.
   */
  public function onTerminate(TerminateEvent $event): void {
    $this->nonce->reset();
  }

  /**
   * Builds and sets the CSP header, unless another module already set one.
   */
  private function setContentSecurityPolicy(ImmutableConfig $config, Request $request, Response $response): void {
    $headers = $response->headers;
    if ($headers->has('Content-Security-Policy') || $headers->has('Content-Security-Policy-Report-Only')) {
      return;
    }

    $nonce = NULL;
    if ($config->get('csp.use_nonce')) {
      if ($this->nonce->isConsumed()) {
        // Markup depends on this nonce: the header must carry it and the
        // response must not be served to anyone else from the page cache.
        $nonce = $this->nonce->getNonce();
        $this->pageCacheKillSwitch->trigger();
      }
      elseif (!$this->isPageCacheable($request, $response)) {
        $nonce = $this->nonce->getNonce();
      }
      // Otherwise the response may be stored in the anonymous page cache; a
      // nonce in a shared cached header is worthless, so none is emitted.
    }

    /** @var array<string, list<string>> $extra */
    $extra = $config->get('csp.extra') ?? [];
    $reportUri = (string) $config->get('csp.report_uri');
    $policy = $this->policyBuilder->build(
      $extra,
      (bool) $config->get('csp.style_unsafe_inline'),
      (bool) $config->get('csp.upgrade_insecure_requests'),
      $nonce,
      $reportUri,
    );
    if ($policy === '') {
      return;
    }
    $name = $config->get('csp.report_only') ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
    $headers->set($name, $policy);

    $endpoints = PolicyBuilder::reportingEndpoints($reportUri);
    if ($endpoints !== '' && !$headers->has('Reporting-Endpoints')) {
      $headers->set('Reporting-Endpoints', $endpoints);
    }
  }

  /**
   * Whether the anonymous page cache would store this response.
   */
  private function isPageCacheable(Request $request, Response $response): bool {
    if (!$response instanceof CacheableResponseInterface) {
      return FALSE;
    }
    if ($this->pageCacheRequestPolicy->check($request) !== RequestPolicyInterface::ALLOW) {
      return FALSE;
    }
    return $this->pageCacheResponsePolicy->check($response, $request) !== ResponsePolicyInterface::DENY;
  }

  /**
   * Builds the Strict-Transport-Security value from config.
   */
  private static function hstsValue(ImmutableConfig $config): string {
    $maxAge = max(0, (int) $config->get('hsts.max_age'));
    $value = 'max-age=' . $maxAge;
    if ($config->get('hsts.include_subdomains')) {
      $value .= '; includeSubDomains';
    }
    // The preload token is only valid together with includeSubDomains and a max-age of
    // at least one year (hstspreload.org requirements).
    if ($config->get('hsts.preload') && $config->get('hsts.include_subdomains') && $maxAge >= 31536000) {
      $value .= '; preload';
    }
    return $value;
  }

}
