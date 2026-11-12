<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenintra_observability\Unit;

use Drupal\aabenintra_observability\Logger\ChannelHandlers;
use Drupal\aabenintra_observability\Logger\IpAnonymizer;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the channel_handlers parameter helper and the IP anonymiser.
 */
#[CoversClass(\Drupal\aabenintra_observability\Logger\ChannelHandlers::class)]
#[Group('aabenintra_observability')]
final class ChannelHandlersTest extends UnitTestCase {

  /**
   * Append to simple syntax.
   */
  public function testAppendToSimpleSyntax(): void {
    $parameter = ['default' => ['aabenintra_stderr'], 'php' => ['error_log']];
    $result = ChannelHandlers::append($parameter, 'default', 'drupal.raven', NULL, ['ip']);

    self::assertSame([
      'handlers' => [
        ['name' => 'aabenintra_stderr'],
        ['name' => 'drupal.raven', 'processors' => ['ip']],
      ],
    ], $result['default']);
    self::assertSame(['error_log'], $result['php'], 'Other channels are untouched.');
    self::assertTrue(ChannelHandlers::has($result, 'default', 'drupal.raven'));
    self::assertFalse(ChannelHandlers::has($result, 'php', 'drupal.raven'));
  }

  /**
   * Append to nested syntax is idempotent.
   */
  public function testAppendToNestedSyntaxIsIdempotent(): void {
    $parameter = ['default' => ['handlers' => [['name' => 'aabenintra_stderr', 'formatter' => 'aabenintra_ecs']]]];
    $once = ChannelHandlers::append($parameter, 'default', 'drupal.dblog');
    $twice = ChannelHandlers::append($once, 'default', 'drupal.dblog');

    self::assertSame($once, $twice);
    self::assertCount(2, $once['default']['handlers']);
    self::assertSame('aabenintra_ecs', $once['default']['handlers'][0]['formatter']);
    self::assertSame(['name' => 'drupal.dblog'], $once['default']['handlers'][1]);
  }

  /**
   * Append creates missing channel.
   */
  public function testAppendCreatesMissingChannel(): void {
    $result = ChannelHandlers::append([], 'default', 'aabenintra_stderr', 'aabenintra_ecs');
    self::assertSame(['default' => ['handlers' => [['name' => 'aabenintra_stderr', 'formatter' => 'aabenintra_ecs']]]], $result);
  }

  /**
   * Ip anonymizer.
   */
  public function testIpAnonymizer(): void {
    self::assertSame('10.1.2.0', IpAnonymizer::anonymize('10.1.2.3'));
    self::assertSame('2001:db8::', IpAnonymizer::anonymize('2001:db8::1'));
    self::assertNull(IpAnonymizer::anonymize('not-an-ip'));
  }

}
