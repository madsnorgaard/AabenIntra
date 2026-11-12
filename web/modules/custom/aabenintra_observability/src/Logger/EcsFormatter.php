<?php

declare(strict_types=1);

namespace Drupal\aabenintra_observability\Logger;

use Drupal\aabenintra_observability\ObservabilityEnvironment;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\LogRecord;

/**
 * Formats a Monolog record as one ECS-shaped JSON line.
 *
 * Elastic Common Schema field names are used so the same Loki/Grafana queries
 * work across tenants and so the lines import cleanly into ECS-aware tools.
 * Only the fields that are actually useful in an intranet context are emitted
 * (see docs/OBSERVABILITY.md for the full list); account names, e-mail
 * addresses and query strings are deliberately left out.
 */
final class EcsFormatter extends NormalizerFormatter {

  public const ECS_VERSION = '8.11.0';

  /**
   * Context keys Drupal adds to every record; mapped to ECS fields or dropped.
   */
  private const DRUPAL_CONTEXT_KEYS = [
    'channel',
    'uid',
    'ip',
    'request_uri',
    'referer',
    'timestamp',
    'link',
    'backtrace',
    'exception',
    'severity_level',
    '@backtrace_string',
  ];

  public function __construct(
    private readonly string $environment,
    private readonly ?string $tenant,
    private readonly string $version,
    private readonly bool $anonymizeIp = TRUE,
    private readonly ?string $hostName = NULL,
  ) {
    parent::__construct();
  }

  /**
   * Service factory: configuration from the process environment.
   */
  public static function fromEnvironment(?ObservabilityEnvironment $env = NULL): self {
    $env ??= ObservabilityEnvironment::fromServer();
    $host = gethostname();
    return new self(
      environment: $env->environment,
      tenant: $env->tenant,
      version: $env->release,
      anonymizeIp: $env->anonymizeIp,
      hostName: $host === FALSE ? NULL : $host,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function format(LogRecord $record): string {
    return $this->toJson($this->toEcs($record), TRUE) . "\n";
  }

  /**
   * {@inheritdoc}
   */
  public function formatBatch(array $records): string {
    $out = '';
    foreach ($records as $record) {
      $out .= $this->format($record);
    }
    return $out;
  }

  /**
   * Maps a record to the ECS document (before JSON encoding).
   *
   * @return array<string, mixed>
   *   The ECS document.
   */
  public function toEcs(LogRecord $record): array {
    $context = $record->context;
    $extra = $record->extra;

    $doc = [
      '@timestamp' => $record->datetime
        ->setTimezone(new \DateTimeZone('UTC'))
        ->format('Y-m-d\TH:i:s.v\Z'),
      'ecs' => ['version' => self::ECS_VERSION],
      'log' => [
        'level' => strtolower($record->level->getName()),
        'logger' => $record->channel,
      ],
      'message' => $record->message,
      'labels' => ['channel' => $record->channel],
      'event' => ['dataset' => 'drupal.' . $record->channel],
      'service' => [
        'name' => ObservabilityEnvironment::SERVICE_NAME,
        'environment' => $this->environment,
        'version' => $this->version,
      ],
    ];
    if ($this->tenant !== NULL) {
      $doc['tenant'] = $this->tenant;
    }
    if ($this->hostName !== NULL) {
      $doc['host'] = ['name' => $this->hostName];
    }

    $uid = $extra['uid'] ?? $context['uid'] ?? NULL;
    if ($uid !== NULL && $uid !== '') {
      $doc['user'] = ['id' => (string) $uid];
    }

    $uri = $extra['request_uri'] ?? $context['request_uri'] ?? '';
    if (is_string($uri) && $uri !== '') {
      $doc['url'] = $this->url($uri);
    }

    $ip = $extra['ip'] ?? $context['ip'] ?? '';
    if (is_string($ip) && $ip !== '') {
      $clientIp = $this->anonymizeIp ? IpAnonymizer::anonymize($ip) : $ip;
      if ($clientIp !== NULL) {
        $doc['client'] = ['ip' => $clientIp];
      }
    }

    $referer = $extra['referer'] ?? $context['referer'] ?? '';
    if (is_string($referer) && $referer !== '') {
      $doc['http'] = ['request' => ['referrer' => $referer]];
    }

    if (isset($extra['memory_usage']) && is_int($extra['memory_usage'])) {
      $doc['drupal']['memory_bytes'] = $extra['memory_usage'];
    }

    $exception = $context['exception'] ?? NULL;
    if ($exception instanceof \Throwable) {
      $doc['error'] = $this->error($exception);
    }

    foreach (self::DRUPAL_CONTEXT_KEYS as $key) {
      unset($context[$key]);
    }
    if ($context !== []) {
      $doc['drupal']['context'] = $this->normalize($context);
    }

    return $doc;
  }

  /**
   * ECS url.* from the request URI; the query string is dropped on purpose.
   *
   * @return array<string, string>
   *   url.path and, when present, url.domain.
   */
  private function url(string $uri): array {
    $parts = parse_url($uri);
    if ($parts === FALSE) {
      return ['original' => $uri];
    }
    $url = ['path' => $parts['path'] ?? '/'];
    if (isset($parts['host'])) {
      $url['domain'] = $parts['host'];
    }
    return $url;
  }

  /**
   * ECS error.* from a throwable.
   *
   * @return array<string, mixed>
   *   error.type, error.message, error.code and error.stack_trace.
   */
  private function error(\Throwable $e): array {
    return [
      'type' => $e::class,
      'message' => $e->getMessage(),
      'code' => (string) $e->getCode(),
      'stack_trace' => sprintf("%s(%d)\n%s", $e->getFile(), $e->getLine(), $e->getTraceAsString()),
    ];
  }

}
