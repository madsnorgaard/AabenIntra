<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenintra_observability\Unit;

use Drupal\aabenintra_observability\Logger\EcsFormatter;
use Drupal\Tests\UnitTestCase;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the ECS field mapping of the JSON formatter.
 */
#[CoversClass(\Drupal\aabenintra_observability\Logger\EcsFormatter::class)]
#[Group('aabenintra_observability')]
final class EcsFormatterTest extends UnitTestCase {

  /**
   * Test fixture.
   */
  private function record(Level $level = Level::Error, string $message = 'Something broke', array $context = [], array $extra = []): LogRecord {
    return new LogRecord(
      datetime: new \DateTimeImmutable('2026-11-08T13:45:12.123+01:00'),
      channel: 'php',
      level: $level,
      message: $message,
      context: $context,
      extra: $extra,
    );
  }

  /**
   * Test fixture.
   */
  private function formatter(bool $anonymize = TRUE): EcsFormatter {
    return new EcsFormatter('production', 'intranet.kommune.dk', 'abc1234', $anonymize, 'c0ffee');
  }

  /**
   * Core field mapping.
   */
  public function testCoreFieldMapping(): void {
    $doc = $this->formatter()->toEcs($this->record(extra: [
      'uid' => 42,
      'user' => 'jens',
      'request_uri' => 'https://intranet.kommune.dk/node/5?token=secret',
      'ip' => '192.0.2.77',
      'referer' => 'https://intranet.kommune.dk/dashboard',
      'memory_usage' => 12345678,
    ]));

    self::assertSame('2026-11-08T12:45:12.123Z', $doc['@timestamp']);
    self::assertSame('error', $doc['log']['level']);
    self::assertSame('php', $doc['log']['logger']);
    self::assertSame('php', $doc['labels']['channel']);
    self::assertSame('drupal.php', $doc['event']['dataset']);
    self::assertSame('Something broke', $doc['message']);
    self::assertSame(['name' => 'aabenintra', 'environment' => 'production', 'version' => 'abc1234'], $doc['service']);
    self::assertSame('intranet.kommune.dk', $doc['tenant']);
    self::assertSame('c0ffee', $doc['host']['name']);
    self::assertSame('42', $doc['user']['id']);
    self::assertSame(['path' => '/node/5', 'domain' => 'intranet.kommune.dk'], $doc['url'], 'Query strings are dropped.');
    self::assertSame('192.0.2.0', $doc['client']['ip'], 'IPv4 anonymised to /24 by default.');
    self::assertSame('https://intranet.kommune.dk/dashboard', $doc['http']['request']['referrer']);
    self::assertSame(12345678, $doc['drupal']['memory_bytes']);
    self::assertArrayNotHasKey('context', $doc['drupal']);
    self::assertStringNotContainsString('jens', json_encode($doc, JSON_THROW_ON_ERROR), 'Account names never reach the log.');
  }

  /**
   * Ip kept when anonymisation off.
   */
  public function testIpKeptWhenAnonymisationOff(): void {
    $doc = $this->formatter(FALSE)->toEcs($this->record(extra: ['ip' => '2001:db8:1:2:3:4:5:6']));
    self::assertSame('2001:db8:1:2:3:4:5:6', $doc['client']['ip']);

    $doc = $this->formatter()->toEcs($this->record(extra: ['ip' => '2001:db8:1:2:3:4:5:6']));
    self::assertSame('2001:db8:1:2::', $doc['client']['ip']);
  }

  /**
   * Exception and remaining context.
   */
  public function testExceptionAndRemainingContext(): void {
    $e = new \RuntimeException('db gone', 2002);
    $doc = $this->formatter()->toEcs($this->record(context: [
      'exception' => $e,
      'backtrace' => [['file' => 'x']],
      '@backtrace_string' => '#0 ...',
      'channel' => 'php',
      'uid' => 0,
      'ip' => '',
      'request_uri' => '',
      'referer' => '',
      'timestamp' => 1,
      'link' => '',
      'severity_level' => 3,
      'nid' => 17,
    ]));

    self::assertSame(\RuntimeException::class, $doc['error']['type']);
    self::assertSame('db gone', $doc['error']['message']);
    self::assertSame('2002', $doc['error']['code']);
    self::assertStringContainsString(__FILE__, $doc['error']['stack_trace']);
    self::assertSame(['nid' => 17], $doc['drupal']['context'], 'Only non-standard context keys survive.');
    self::assertArrayNotHasKey('url', $doc);
    self::assertArrayNotHasKey('client', $doc);
  }

  /**
   * Format is one json line per record.
   */
  public function testFormatIsOneJsonLinePerRecord(): void {
    $formatter = new EcsFormatter('production', NULL, 'dev');
    $out = $formatter->formatBatch([$this->record(Level::Warning, 'a'), $this->record(Level::Info, 'b')]);
    $lines = explode("\n", rtrim($out, "\n"));
    self::assertCount(2, $lines);
    $first = json_decode($lines[0], TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertSame('warning', $first['log']['level']);
    self::assertArrayNotHasKey('tenant', $first);
    self::assertArrayNotHasKey('host', $first);
    self::assertStringEndsWith("\n", $out);
  }

}
