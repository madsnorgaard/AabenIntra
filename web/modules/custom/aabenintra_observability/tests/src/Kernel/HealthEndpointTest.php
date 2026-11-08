<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenintra_observability\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Exercises GET /health through the real HTTP kernel.
 *
 * Needs a database (SIMPLETEST_DB), so it runs in CI rather than on a
 * developer machine without one.
 */
/**
 * Tests the /health endpoint end to end.
 */
#[Group('aabenintra_observability')]
final class HealthEndpointTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'monolog', 'aabenintra_observability'];

  /**
   * Health is anonymous uncached json.
   */
  public function testHealthIsAnonymousUncachedJson(): void {
    $response = $this->container->get('http_kernel')->handle(Request::create('/health'));

    self::assertSame(200, $response->getStatusCode());
    self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
    self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

    $body = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertSame(['status', 'db', 'cache', 'version'], array_keys($body), 'No extra detail leaks.');
    self::assertSame('ok', $body['status']);
    self::assertSame('ok', $body['db']);
    self::assertSame('ok', $body['cache']);
    self::assertIsString($body['version']);
    self::assertNotSame('', $body['version']);
  }

}
