<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenintra_audit\Unit;

use Drupal\aabenintra_audit\IpAnonymiser;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests IP truncation used for GDPR data minimisation.
 */
#[CoversClass(IpAnonymiser::class)]
#[Group('aabenintra_audit')]
final class IpAnonymiserTest extends UnitTestCase {

  /**
   * @return array<string,array{string,string}>
   */
  public static function provideAddresses(): array {
    return [
      'ipv4' => ['203.0.113.42', '203.0.113.0'],
      'ipv4 already zero' => ['10.0.0.0', '10.0.0.0'],
      'ipv4 with spaces' => ['  192.0.2.7 ', '192.0.2.0'],
      'ipv6 full' => ['2001:db8:85a3:8d3:1319:8a2e:370:7348', '2001:db8:85a3:8d3::'],
      'ipv6 compressed' => ['2001:db8::1', '2001:db8::'],
      'ipv6 loopback' => ['::1', '::'],
      'ipv4-mapped ipv6' => ['::ffff:192.0.2.128', '::'],
      'empty' => ['', ''],
      'garbage' => ['not-an-ip', ''],
      'ipv4 out of range' => ['256.1.1.1', ''],
      'sql-ish' => ["1.2.3.4'; DROP TABLE x;--", ''],
    ];
  }

  #[DataProvider('provideAddresses')]
  public function testAnonymise(string $input, string $expected): void {
    $this->assertSame($expected, IpAnonymiser::anonymise($input));
  }

  public function testIsAnonymised(): void {
    $this->assertTrue(IpAnonymiser::isAnonymised(''));
    $this->assertTrue(IpAnonymiser::isAnonymised('203.0.113.0'));
    $this->assertFalse(IpAnonymiser::isAnonymised('203.0.113.42'));
    $this->assertTrue(IpAnonymiser::isAnonymised('2001:db8::'));
    $this->assertFalse(IpAnonymiser::isAnonymised('2001:db8::1'));
  }

  public function testIdempotent(): void {
    foreach (['203.0.113.42', '2001:db8:85a3:8d3:1319:8a2e:370:7348'] as $ip) {
      $once = IpAnonymiser::anonymise($ip);
      $this->assertSame($once, IpAnonymiser::anonymise($once));
    }
  }

}
