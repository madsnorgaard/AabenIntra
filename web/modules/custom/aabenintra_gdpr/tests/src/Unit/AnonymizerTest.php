<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenintra_gdpr\Unit;

use Drupal\aabenintra_gdpr\Anonymizer;
use Drupal\aabenintra_gdpr\ErasureMode;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Placeholder values used when an account is pseudonymised.
 */
#[CoversClass(Anonymizer::class)]
#[CoversClass(ErasureMode::class)]
#[Group('aabenintra_gdpr')]
final class AnonymizerTest extends UnitTestCase {

  private Anonymizer $anonymizer;

  protected function setUp(): void {
    parent::setUp();
    $this->anonymizer = new Anonymizer();
  }

  public function testPlaceholdersAreDeterministicAndUidScoped(): void {
    $this->assertSame('deleted-user-42', $this->anonymizer->name(42));
    $this->assertSame('deleted-42@invalid.invalid', $this->anonymizer->mail(42));
    $this->assertSame($this->anonymizer->name(42), $this->anonymizer->name(42));
    $this->assertNotSame($this->anonymizer->mail(42), $this->anonymizer->mail(43));
  }

  /**
   * The mail placeholder must be a syntactically valid address on a reserved
   * TLD (RFC 2606), so Drupal's e-mail validation accepts it and nothing can
   * ever be delivered to it.
   */
  public function testMailIsValidButUndeliverable(): void {
    $mail = $this->anonymizer->mail(7);
    $this->assertNotFalse(filter_var($mail, FILTER_VALIDATE_EMAIL));
    $this->assertStringEndsWith('.invalid', $mail);
  }

  public function testIsAnonymisedRecognisesPlaceholders(): void {
    $this->assertTrue($this->anonymizer->isAnonymised('deleted-user-9', 'deleted-9@invalid.invalid'));
    $this->assertFalse($this->anonymizer->isAnonymised('jane', 'jane@example.org'));
    $this->assertFalse($this->anonymizer->isAnonymised('deleted-user-9', NULL));
    $this->assertFalse($this->anonymizer->isAnonymised('deleted-user-9', 'jane@example.org'));
  }

  public function testSubjectHashIsSaltedSha256(): void {
    $hash = $this->anonymizer->subjectHash(42, 'salt-a');
    $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    $this->assertSame($hash, $this->anonymizer->subjectHash(42, 'salt-a'));
    $this->assertNotSame($hash, $this->anonymizer->subjectHash(42, 'salt-b'));
    $this->assertNotSame($hash, $this->anonymizer->subjectHash(43, 'salt-a'));
    $this->assertNotSame(hash('sha256', '42'), $hash, 'An unsalted uid hash must not match.');
  }

  public function testRandomPasswordIsLongAndUnique(): void {
    $a = $this->anonymizer->randomPassword();
    $this->assertSame(64, strlen($a));
    $this->assertNotSame($a, $this->anonymizer->randomPassword());
  }

  public function testErasureModeOptionParsing(): void {
    $this->assertSame(ErasureMode::Anonymise, ErasureMode::fromOption('anonymise'));
    $this->assertSame(ErasureMode::Anonymise, ErasureMode::fromOption(' Anonymize '));
    $this->assertSame(ErasureMode::Delete, ErasureMode::fromOption('delete'));
    $this->expectException(\InvalidArgumentException::class);
    ErasureMode::fromOption('purge');
  }

}
