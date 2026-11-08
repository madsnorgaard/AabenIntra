<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenintra_security\Kernel;

use Drupal\Core\Cache\CacheableResponse;
use Drupal\aabenintra_security\Csp\PolicyBuilder;
use Drupal\Core\PageCache\ResponsePolicyInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Dispatches real kernel RESPONSE events through the installed module.
 *
 * Proves the service wiring, the config/install defaults and the config
 * schema, and that the headers coexist with core's FinishResponseSubscriber.
 */
#[Group('aabenintra_security')]
#[RunTestsInSeparateProcesses]
final class SecurityHeadersTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'aabenintra_security'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'aabenintra_security']);
  }

  /**
   * Runs the KernelEvents::RESPONSE chain for a main request.
   */
  private function respond(string $url): Response {
    $request = Request::create($url);
    $this->container->get('request_stack')->push($request);
    $response = new CacheableResponse('<html lang="da"></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    $event = new ResponseEvent($this->container->get('http_kernel'), $request, HttpKernelInterface::MAIN_REQUEST, $response);
    $this->container->get('event_dispatcher')->dispatch($event, KernelEvents::RESPONSE);
    $this->container->get('request_stack')->pop();
    return $event->getResponse();
  }

  /**
   * Headers on an HTML response over HTTPS with the shipped defaults.
   */
  public function testDefaultHeadersOverHttps(): void {
    $response = $this->respond('https://intranet.example.dk/dashboard');
    $h = $response->headers;

    // Core's FinishResponseSubscriber values are kept, not duplicated.
    $this->assertSame('nosniff', $h->get('X-Content-Type-Options'));
    $this->assertSame('SAMEORIGIN', $h->get('X-Frame-Options'));

    $this->assertSame('max-age=31536000; includeSubDomains', $h->get('Strict-Transport-Security'));
    $this->assertSame('strict-origin-when-cross-origin', $h->get('Referrer-Policy'));
    $this->assertStringContainsString('camera=()', (string) $h->get('Permissions-Policy'));

    $this->assertFalse($h->has('Content-Security-Policy'));
    $csp = (string) $h->get('Content-Security-Policy-Report-Only');
    // Every baseline directive is present with exactly its baseline sources,
    // in the builder's emission order, with nothing appended.
    $directives = explode('; ', $csp);
    $expected = [];
    foreach (PolicyBuilder::DIRECTIVES as $directive) {
      $expected[] = $directive . ' ' . implode(' ', PolicyBuilder::BASELINE[$directive]);
    }
    $expected[] = 'upgrade-insecure-requests';
    $this->assertSame($expected, $directives);
    $this->assertStringContainsString("style-src 'self' 'unsafe-inline'", $csp);
    $this->assertStringContainsString("frame-ancestors 'self'", $csp);
    $this->assertStringContainsString("object-src 'none'", $csp);
    $this->assertStringNotContainsString('nonce-', $csp);

    $this->assertInstanceOf(CacheableResponse::class, $response);
    $this->assertContains('config:aabenintra_security.settings', $response->getCacheableMetadata()->getCacheTags());
  }

  /**
   * HSTS is never sent on plain HTTP.
   */
  public function testNoHstsOverHttp(): void {
    $response = $this->respond('http://intranet.example.dk/');
    $this->assertFalse($response->headers->has('Strict-Transport-Security'));
    $this->assertTrue($response->headers->has('Content-Security-Policy-Report-Only'));
  }

  /**
   * Enforce mode, extra sources and a report endpoint from configuration.
   */
  public function testEnforceWithExtraSources(): void {
    $this->config('aabenintra_security.settings')
      ->set('csp.report_only', FALSE)
      ->set('csp.report_uri', 'https://reports.example.dk/csp')
      ->set('csp.extra.frame_src', ['https://login.example.dk'])
      ->set('csp.extra.connect_src', ['https://login.example.dk', 'wss://chat.example.dk'])
      ->set('hsts.preload', TRUE)
      ->save();

    $response = $this->respond('https://intranet.example.dk/');
    $h = $response->headers;
    $this->assertFalse($h->has('Content-Security-Policy-Report-Only'));
    $csp = (string) $h->get('Content-Security-Policy');
    $this->assertStringContainsString("connect-src 'self' https://login.example.dk wss://chat.example.dk; ", $csp);
    $this->assertStringContainsString("frame-src 'self' https://login.example.dk; ", $csp);
    $this->assertStringEndsWith('report-uri https://reports.example.dk/csp; report-to csp-endpoint', $csp);
    $this->assertSame('csp-endpoint="https://reports.example.dk/csp"', $h->get('Reporting-Endpoints'));
    $this->assertSame('max-age=31536000; includeSubDomains; preload', $h->get('Strict-Transport-Security'));
  }

  /**
   * The master switch removes every header the module owns.
   */
  public function testDisabled(): void {
    $this->config('aabenintra_security.settings')->set('enabled', FALSE)->save();
    $response = $this->respond('https://intranet.example.dk/');
    $h = $response->headers;
    $this->assertFalse($h->has('Content-Security-Policy-Report-Only'));
    $this->assertFalse($h->has('Strict-Transport-Security'));
    $this->assertFalse($h->has('Referrer-Policy'));
    $this->assertFalse($h->has('Permissions-Policy'));
    // Core still protects these.
    $this->assertSame('nosniff', $h->get('X-Content-Type-Options'));
    $this->assertSame('SAMEORIGIN', $h->get('X-Frame-Options'));
  }

  /**
   * The nonce reaches the header once Twig has consumed it.
   */
  public function testNonceViaTwigFunction(): void {
    $this->config('aabenintra_security.settings')->set('csp.use_nonce', TRUE)->save();
    /** @var \Drupal\aabenintra_security\Twig\CspNonceExtension $extension */
    $extension = $this->container->get('aabenintra_security.twig_extension');
    $rendered = $extension->nonce();
    $this->assertSame(0, $rendered['#cache']['max-age']);

    $response = $this->respond('https://intranet.example.dk/');
    $csp = (string) $response->headers->get('Content-Security-Policy-Report-Only');
    $this->assertStringContainsString("script-src 'self' 'nonce-" . $rendered['#plain_text'] . "'; ", $csp);
    $killSwitch = $this->container->get('page_cache_kill_switch');
    $this->assertSame(ResponsePolicyInterface::DENY, $killSwitch->check($response, Request::create('https://intranet.example.dk/')));

    // The Twig environment knows the function.
    $this->assertNotNull($this->container->get('twig')->getFunction('csp_nonce'));
  }

}
