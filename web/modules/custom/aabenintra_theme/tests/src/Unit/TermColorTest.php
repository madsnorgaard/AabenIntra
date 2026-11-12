<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenintra_theme\Unit;

use Drupal\aabenintra_theme\TermColor;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Deterministic term colours and the explicit-override rules.
 */
#[CoversClass(TermColor::class)]
#[Group('aabenintra_theme')]
final class TermColorTest extends UnitTestCase {

  private TermColor $termColor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->termColor = new TermColor();
  }

  /**
   * Slots are stable per id and wrap around the palette.
   */
  public function testSlotIsDeterministic(): void {
    $this->assertSame(TermColor::slot(3), TermColor::slot(3));
    $this->assertSame(TermColor::slot(0), TermColor::slot(8), 'Eight palette entries.');
    $this->assertSame(TermColor::slot('5'), TermColor::slot(5), 'String ids are accepted.');
    $this->assertNotSame(TermColor::slot(1), TermColor::slot(2));
    $this->assertStringStartsWith('oklch(', TermColor::slot(NULL));
  }

  /**
   * A valid hex in field_color wins over the palette, normalised to lowercase.
   */
  public function testExplicitColorOverridesPalette(): void {
    $term = $this->term(4, ['field_color' => ' #A1B2C3 ']);
    $this->assertSame('#a1b2c3', $this->termColor->color($term));
  }

  /**
   * Anything that is not #rrggbb falls back to the palette slot.
   */
  public function testInvalidColorFallsBackToSlot(): void {
    $term = $this->term(4, ['field_color' => 'red']);
    $this->assertSame(TermColor::slot(4), $this->termColor->color($term));

    $empty = $this->term(4, ['field_color' => '']);
    $this->assertSame(TermColor::slot(4), $this->termColor->color($empty));

    $this->assertSame(TermColor::slot(NULL), $this->termColor->color(NULL));
  }

  /**
   * Icons are optional and trimmed; blanks are treated as absent.
   */
  public function testIcon(): void {
    $this->assertNull($this->termColor->icon(NULL));
    $this->assertNull($this->termColor->icon($this->term(1, [])));
    $this->assertNull($this->termColor->icon($this->term(1, ['field_icon' => '  '])));
    $this->assertSame('OK', $this->termColor->icon($this->term(1, ['field_icon' => ' OK '])));
  }

  /**
   * Builds a term mock exposing the given single-value fields.
   *
   * @param array<string,string> $fields
   */
  private function term(int $id, array $fields): TermInterface {
    $term = $this->createMock(TermInterface::class);
    $term->method('id')->willReturn($id);
    $term->method('hasField')->willReturnCallback(static fn(string $name): bool => array_key_exists($name, $fields));
    $term->method('get')->willReturnCallback(function (string $name) use ($fields): FieldItemListInterface {
      $list = $this->createMock(FieldItemListInterface::class);
      $list->method('isEmpty')->willReturn($fields[$name] === '');
      $list->method('__get')->with('value')->willReturn($fields[$name]);
      return $list;
    });
    return $term;
  }

}
