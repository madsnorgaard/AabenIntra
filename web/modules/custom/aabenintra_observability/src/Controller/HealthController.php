<?php

declare(strict_types=1);

namespace Drupal\aabenintra_observability\Controller;

use Drupal\aabenintra_observability\ObservabilityEnvironment;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Liveness/readiness probe for Traefik health checks and blackbox exporters.
 *
 * Anonymous, never cached, and deliberately terse: it says whether the
 * database and the cache backend answer and which release is running, and
 * nothing about hostnames, paths or versions of individual components.
 */
final class HealthController implements ContainerInjectionInterface {

  private const CACHE_KEY = 'aabenintra_observability.health';

  public function __construct(
    private readonly Connection $database,
    private readonly CacheBackendInterface $cache,
    private readonly LoggerInterface $logger,
    private readonly string $version,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('database'),
      $container->get('cache.default'),
      $container->get('logger.factory')->get('aabenintra_observability'),
      ObservabilityEnvironment::fromServer()->release,
    );
  }

  /**
   * GET /health - 200 when every check passes, 503 otherwise.
   */
  public function check(): JsonResponse {
    $db = $this->probe('db', function (): bool {
      return (int) $this->database->query('SELECT 1')->fetchField() === 1;
    });
    $cache = $this->probe('cache', function (): bool {
      $value = bin2hex(random_bytes(8));
      $this->cache->set(self::CACHE_KEY, $value, time() + 60);
      $hit = $this->cache->get(self::CACHE_KEY);
      return $hit !== FALSE && $hit->data === $value;
    });
    $ok = $db && $cache;

    $response = new JsonResponse([
      'status' => $ok ? 'ok' : 'fail',
      'db' => $db ? 'ok' : 'fail',
      'cache' => $cache ? 'ok' : 'fail',
      'version' => $this->version,
    ], $ok ? 200 : 503);
    $response->setPrivate();
    $response->headers->set('Cache-Control', 'no-store, private');
    return $response;
  }

  /**
   * Runs one check; a throwing check is a failed check, logged once.
   */
  private function probe(string $name, callable $check): bool {
    try {
      return (bool) $check();
    }
    catch (\Throwable $e) {
      $this->logger->error('Health check @name failed: @message', [
        '@name' => $name,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

}
