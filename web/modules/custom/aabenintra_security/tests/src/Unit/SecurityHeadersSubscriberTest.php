<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenintra_security\Unit;

use Drupal\aabenintra_security\Csp\NonceService;
use Drupal\aabenintra_security\Csp\PolicyBuilder;
use Drupal\aabenintra_security\EventSubscriber\SecurityHeadersSubscriber;
use Drupal\aabenintra_security\Twig\CspNonceExtension;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\PageCache\RequestPolicyInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\PageCache\ResponsePolicyInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tests the header subscriber against the shipped default configuration.
 */
#[CoversClass(\Drupal\aabenintra_security\EventSubscriber\SecurityHeadersSubscriber::class)]
#[Group('aabenintra_security')]
final class SecurityHeadersSubscriberTest extends UnitTestCase {

  /**
   * Shared nonce service, so tests can read the generated nonce.
   */
  private NonceService $nonce;

  /**
   * Real kill switch, so tests can observe it being triggered.
   */
  private KillSwitch $killSwitch;

  /**
   * What the mocked page cache request policy answers.
   */
  private bool $pageCacheable = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->nonce = new NonceService();
    $this->killSwitch = new KillSwitch();
  }

  /**
   * Loads config/install defaults merged with overrides.
   *
   * @param array<string, mixed> $overrides
   *   Dotted keys (for example 'csp.report_only') and values.
   */
  private function createSubscriber(array $overrides = []): SecurityHeadersSubscriber {
    $file = dirname(__DIR__, 3) . '/config/install/aabenintra_security.settings.yml';
    $data = Yaml::parseFile($file);
    foreach ($overrides as $key => $value) {
      $ref = &$data;
      foreach (explode('.', $key) as $part) {
        $ref = &$ref[$part];
      }
      $ref = $value;
      unset($ref);
    }
    $config = new ImmutableConfig(
      SecurityHeadersSubscriber::CONFIG_NAME,
      $this->createMock(StorageInterface::class),
      $this->createMock(EventDispatcherInterface::class),
      $this->createMock(TypedConfigManagerInterface::class),
    );
    $config->initWithData($data);

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with(SecurityHeadersSubscriber::CONFIG_NAME)->willReturn($config);

    $requestPolicy = $this->createMock(RequestPolicyInterface::class);
    $requestPolicy->method('check')->willReturnCallback(fn() => $this->pageCacheable ? RequestPolicyInterface::ALLOW : RequestPolicyInterface::DENY);
    $responsePolicy = $this->createMock(ResponsePolicyInterface::class);
    $responsePolicy->method('check')->willReturn(NULL);

    return new SecurityHeadersSubscriber($factory, new PolicyBuilder(), $this->nonce, $requestPolicy, $responsePolicy, $this->killSwitch);
  }

  /**
   * Runs the subscriber for one response and returns it.
   */
  private function dispatch(SecurityHeadersSubscriber $subscriber, Request $request, CacheableResponse $response, int $type = HttpKernelInterface::MAIN_REQUEST): CacheableResponse {
    $event = new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, $type, $response);
    $subscriber->onResponse($event);
    return $response;
  }

  /**
   * A cacheable text/html response, as the page cache would see it.
   */
  private static function htmlResponse(): CacheableResponse {
    return new CacheableResponse('<html lang="da"></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
  }

  /**
   * Every header the module owns, with the shipped defaults.
   */
  public function testDefaultsOnHttps(): void {
    $response = $this->dispatch($this->createSubscriber(), Request::create('https://intranet.example.dk/dashboard'), self::htmlResponse());
    $h = $response->headers;

    $this->assertSame('max-age=31536000; includeSubDomains', $h->get('Strict-Transport-Security'));
    $this->assertSame('strict-origin-when-cross-origin', $h->get('Referrer-Policy'));
    $this->assertSame('camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()', $h->get('Permissions-Policy'));
    $this->assertSame('nosniff', $h->get('X-Content-Type-Options'));
    $this->assertSame('SAMEORIGIN', $h->get('X-Frame-Options'));

    $this->assertFalse($h->has('Content-Security-Policy'), 'first rollout is report-only');
    $csp = (string) $h->get('Content-Security-Policy-Report-Only');
    $this->assertStringStartsWith("default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; ", $csp);
    $this->assertStringContainsString("frame-ancestors 'self'; ", $csp);
    $this->assertStringContainsString("object-src 'none'; upgrade-insecure-requests", $csp);
    $this->assertStringNotContainsString('nonce-', $csp);
    $this->assertStringNotContainsString('report-', $csp);
    $this->assertFalse($h->has('Reporting-Endpoints'));

    $this->assertContains('config:aabenintra_security.settings', $response->getCacheableMetadata()->getCacheTags());

    $events = SecurityHeadersSubscriber::getSubscribedEvents();
    $this->assertSame(['onResponse', -10], $events[KernelEvents::RESPONSE], 'runs after core FinishResponseSubscriber (0) and dynamic page cache (7)');
  }

  /**
   * HSTS needs TLS; subrequests are left alone.
   */
  public function testNoHstsOverHttpAndNoHeadersOnSubrequests(): void {
    $response = $this->dispatch($this->createSubscriber(), Request::create('http://intranet.example.dk/'), self::htmlResponse());
    $this->assertFalse($response->headers->has('Strict-Transport-Security'));
    $this->assertTrue($response->headers->has('Content-Security-Policy-Report-Only'));

    $sub = $this->dispatch($this->createSubscriber(), Request::create('https://intranet.example.dk/'), self::htmlResponse(), HttpKernelInterface::SUB_REQUEST);
    $this->assertFalse($sub->headers->has('Content-Security-Policy-Report-Only'));
    $this->assertFalse($sub->headers->has('Strict-Transport-Security'));
  }

  /**
   * Behind Traefik the scheme comes from X-Forwarded-Proto.
   */
  public function testForwardedProtoBehindTrustedProxy(): void {
    $request = Request::create('http://intranet.example.dk/', 'GET', [], [], [], [
      'REMOTE_ADDR' => '172.18.0.2',
      'HTTP_X_FORWARDED_PROTO' => 'https',
    ]);
    Request::setTrustedProxies(['172.18.0.2'], Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);
    try {
      $response = $this->dispatch($this->createSubscriber(), $request, self::htmlResponse());
      $this->assertSame('max-age=31536000; includeSubDomains', $response->headers->get('Strict-Transport-Security'));
    }
    finally {
      Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    }
  }

  /**
   * Enforce mode, reporting, preload and extra sources from config.
   */
  public function testEnforceReportUriPreloadAndExtras(): void {
    $subscriber = $this->createSubscriber([
      'csp.report_only' => FALSE,
      'csp.report_uri' => 'https://reports.example.dk/csp',
      'csp.extra.frame_src' => ['https://login.example.dk'],
      'csp.extra.form_action' => ['https://login.example.dk'],
      'hsts.preload' => TRUE,
      'referrer_policy' => '',
    ]);
    $response = $this->dispatch($subscriber, Request::create('https://intranet.example.dk/'), self::htmlResponse());
    $h = $response->headers;
    $this->assertFalse($h->has('Content-Security-Policy-Report-Only'));
    $csp = (string) $h->get('Content-Security-Policy');
    $this->assertStringContainsString("frame-src 'self' https://login.example.dk; ", $csp);
    $this->assertStringContainsString("form-action 'self' https://login.example.dk; ", $csp);
    $this->assertStringEndsWith('report-uri https://reports.example.dk/csp; report-to csp-endpoint', $csp);
    $this->assertSame('csp-endpoint="https://reports.example.dk/csp"', $h->get('Reporting-Endpoints'));
    $this->assertSame('max-age=31536000; includeSubDomains; preload', $h->get('Strict-Transport-Security'));
    $this->assertFalse($h->has('Referrer-Policy'));
  }

  /**
   * The master switch, and headers set by other code are never overwritten.
   */
  public function testDisabledAndExistingHeadersAreRespected(): void {
    $response = $this->dispatch($this->createSubscriber(['enabled' => FALSE]), Request::create('https://intranet.example.dk/'), self::htmlResponse());
    $this->assertFalse($response->headers->has('Content-Security-Policy-Report-Only'));
    $this->assertFalse($response->headers->has('Strict-Transport-Security'));
    $this->assertContains('config:aabenintra_security.settings', $response->getCacheableMetadata()->getCacheTags(), 'config tag is added even when disabled so enabling later invalidates cached pages');

    $preset = self::htmlResponse();
    $preset->headers->set('Content-Security-Policy', "default-src 'none'");
    $preset->headers->set('X-Frame-Options', 'DENY');
    $preset->headers->set('Referrer-Policy', 'no-referrer');
    $response = $this->dispatch($this->createSubscriber(), Request::create('https://intranet.example.dk/'), $preset);
    $this->assertSame("default-src 'none'", $response->headers->get('Content-Security-Policy'));
    $this->assertFalse($response->headers->has('Content-Security-Policy-Report-Only'));
    $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
    $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));

    $response = $this->dispatch($this->createSubscriber(['csp.enabled' => FALSE]), Request::create('https://intranet.example.dk/'), self::htmlResponse());
    $this->assertFalse($response->headers->has('Content-Security-Policy-Report-Only'));
    $this->assertTrue($response->headers->has('Strict-Transport-Security'));
  }

  /**
   * Nonce emission rules relative to the anonymous page cache.
   */
  public function testNonceHandling(): void {
    $request = Request::create('https://intranet.example.dk/');

    // Not consumed, response would be page cached: no nonce in the header.
    $this->pageCacheable = TRUE;
    $response = $this->dispatch($this->createSubscriber(['csp.use_nonce' => TRUE]), $request, self::htmlResponse());
    $this->assertStringNotContainsString('nonce-', (string) $response->headers->get('Content-Security-Policy-Report-Only'));
    $this->assertNull($this->killSwitch->check($response, $request));

    // Not consumed, but not page cacheable (authenticated etc.): nonce present.
    $this->pageCacheable = FALSE;
    $response = $this->dispatch($this->createSubscriber(['csp.use_nonce' => TRUE]), $request, self::htmlResponse());
    $this->assertStringContainsString("'nonce-" . $this->nonce->getNonce() . "'", (string) $response->headers->get('Content-Security-Policy-Report-Only'));

    // Consumed by Twig on a page-cacheable response: nonce present and the
    // page cache kill switch is triggered so the response is never shared.
    $this->pageCacheable = TRUE;
    $this->nonce->reset();
    $twig = new CspNonceExtension($this->nonce);
    $rendered = $twig->nonce();
    $this->assertSame(0, $rendered['#cache']['max-age']);
    $this->assertSame($this->nonce->getNonce(), $rendered['#plain_text']);
    $response = $this->dispatch($this->createSubscriber(['csp.use_nonce' => TRUE]), $request, self::htmlResponse());
    $this->assertStringContainsString("'nonce-" . $rendered['#plain_text'] . "'", (string) $response->headers->get('Content-Security-Policy-Report-Only'));
    $this->assertSame(ResponsePolicyInterface::DENY, $this->killSwitch->check($response, $request));

    // Nonce is base64 of 18 bytes and is fresh after reset.
    $this->assertMatchesRegularExpression('#^[A-Za-z0-9+/]{24}$#', $rendered['#plain_text']);
    $before = $this->nonce->getNonce();
    $this->nonce->reset();
    $this->assertFalse($this->nonce->isConsumed());
    $this->assertNotSame($before, $this->nonce->getNonce());
  }

}
