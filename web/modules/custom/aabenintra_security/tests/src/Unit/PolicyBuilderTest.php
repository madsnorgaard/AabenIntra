<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenintra_security\Unit;

use Drupal\aabenintra_security\Csp\PolicyBuilder;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Content-Security-Policy string builder.
 */
#[CoversClass(\Drupal\aabenintra_security\Csp\PolicyBuilder::class)]
#[Group('aabenintra_security')]
final class PolicyBuilderTest extends UnitTestCase {

  /**
   * The builder under test.
   */
  private PolicyBuilder $builder;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->builder = new PolicyBuilder();
  }

  /**
   * The shipped baseline, in emission order.
   */
  public function testDefaultPolicy(): void {
    $policy = $this->builder->build();
    $expected = "default-src 'self'; "
      . "script-src 'self'; "
      . "style-src 'self' 'unsafe-inline'; "
      . "img-src 'self' data: blob:; "
      . "font-src 'self' data:; "
      . "connect-src 'self'; "
      . "media-src 'self' data: blob:; "
      . "frame-src 'self'; "
      . "frame-ancestors 'self'; "
      . "worker-src 'self' blob:; "
      . "manifest-src 'self'; "
      . "base-uri 'self'; "
      . "form-action 'self'; "
      . "object-src 'none'; "
      . 'upgrade-insecure-requests';
    $this->assertSame($expected, $policy);
  }

  /**
   * Extras accept directive names or config keys and are de-duplicated.
   */
  public function testExtraSourcesAreAppendedAndDeduplicated(): void {
    $policy = $this->builder->build([
      'frame_src' => ['https://login.example.dk', 'https://login.example.dk'],
      'form-action' => ['https://login.example.dk'],
      'connect_src' => ["'self'", 'wss://chat.example.dk'],
      'not-a-directive' => ['https://ignored.example'],
    ]);
    $this->assertStringContainsString("frame-src 'self' https://login.example.dk;", $policy);
    $this->assertStringContainsString("form-action 'self' https://login.example.dk;", $policy);
    $this->assertStringContainsString("connect-src 'self' wss://chat.example.dk;", $policy);
    $this->assertStringNotContainsString('ignored.example', $policy);
    $duplicated = ['frame_src' => ['https://login.example.dk', 'https://login.example.dk']];
    $this->assertSame(["'self'", 'https://login.example.dk'], $this->builder->directives($duplicated)['frame-src']);
  }

  /**
   * Whitespace, semicolons and commas cannot smuggle directives in.
   */
  public function testInvalidSourcesCannotInjectDirectives(): void {
    $policy = $this->builder->build([
      'script_src' => [
        "https://cdn.example; script-src 'unsafe-eval'",
        'https://a.example,https://b.example',
        '',
        '  ',
      ],
    ]);
    $this->assertStringContainsString("script-src 'self'; style-src", $policy);
    $this->assertStringNotContainsString('unsafe-eval', $policy);
    $this->assertStringNotContainsString('a.example', $policy);
    $this->assertNull(PolicyBuilder::sanitizeSource("x y"));
    $this->assertSame('https://ok.example', PolicyBuilder::sanitizeSource('  https://ok.example '));
  }

  /**
   * The 'none' keyword gives way as soon as a real source is configured.
   */
  public function testNoneIsDroppedWhenSourceAdded(): void {
    $directives = $this->builder->directives(['object_src' => ['https://pdf.example']]);
    $this->assertSame(['https://pdf.example'], $directives['object-src']);
    $this->assertSame(["'none'"], $this->builder->directives()['object-src']);
  }

  /**
   * The nonce lands in script-src only.
   */
  public function testNonceIsAddedToScriptSrcOnly(): void {
    $policy = $this->builder->build([], TRUE, TRUE, 'abc123+/=');
    $this->assertStringContainsString("script-src 'self' 'nonce-abc123+/='; ", $policy);
    $this->assertSame(1, substr_count($policy, 'nonce-'));
    $this->assertStringNotContainsString('nonce-', $this->builder->build([], TRUE, TRUE, ''));
  }

  /**
   * The style-src and upgrade-insecure-requests toggles.
   */
  public function testStyleUnsafeInlineAndUpgradeToggles(): void {
    $policy = $this->builder->build([], FALSE, FALSE);
    $this->assertStringContainsString("style-src 'self'; img-src", $policy);
    $this->assertStringNotContainsString('unsafe-inline', $policy);
    $this->assertStringNotContainsString('upgrade-insecure-requests', $policy);
    $this->assertStringEndsWith("object-src 'none'", $policy);
  }

  /**
   * Reporting: report-uri, report-to and the Reporting-Endpoints value.
   */
  public function testReporting(): void {
    $policy = $this->builder->build([], TRUE, TRUE, NULL, 'https://reports.example.dk/csp');
    $this->assertStringEndsWith('upgrade-insecure-requests; report-uri https://reports.example.dk/csp; report-to csp-endpoint', $policy);
    $this->assertSame('csp-endpoint="https://reports.example.dk/csp"', PolicyBuilder::reportingEndpoints('https://reports.example.dk/csp'));

    $this->assertStringEndsWith('report-to csp-endpoint', $this->builder->build([], TRUE, TRUE, NULL, '/csp-report'));

    // Plain http, header injection and garbage are rejected silently.
    foreach (['http://reports.example.dk/csp', 'https://x.example/a; script-src *', 'javascript:alert(1)'] as $bad) {
      $this->assertStringNotContainsString('report-', $this->builder->build([], TRUE, TRUE, NULL, $bad), $bad);
      $this->assertSame('', PolicyBuilder::reportingEndpoints($bad));
    }
  }

  /**
   * Directive to config key mapping and baseline completeness.
   */
  public function testConfigKey(): void {
    $this->assertSame('frame_ancestors', PolicyBuilder::configKey('frame-ancestors'));
    foreach (PolicyBuilder::DIRECTIVES as $directive) {
      $this->assertArrayHasKey($directive, PolicyBuilder::BASELINE);
    }
  }

}
