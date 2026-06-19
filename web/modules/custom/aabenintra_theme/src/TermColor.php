<?php

declare(strict_types=1);

namespace Drupal\aabenintra_theme;

use Drupal\taxonomy\TermInterface;

/**
 * Resolves a display colour (and optional icon) for a taxonomy term.
 *
 * Every term gets a colour with no editor effort: an explicit hex in the term's
 * `field_color` wins, otherwise a deterministic slot from a fixed palette of
 * fresh OKLCH hues keyed by the term id, so categories are visually distinct and
 * stable across requests. Used by both the dashboard tiles and the left-nav term
 * browser so there is a single source of truth.
 */
final class TermColor {

  /**
   * Fresh, cool-leaning palette. Order matters: slot = term id % count.
   *
   * @var list<string>
   */
  private const PALETTE = [
    'oklch(56% 0.13 235)',  /* sky */
    'oklch(58% 0.11 195)',  /* teal */
    'oklch(55% 0.14 300)',  /* violet */
    'oklch(60% 0.15 350)',  /* pink */
    'oklch(64% 0.12 80)',   /* amber */
    'oklch(58% 0.12 158)',  /* green */
    'oklch(62% 0.10 210)',  /* cyan */
    'oklch(50% 0.13 270)',  /* indigo */
  ];

  /**
   * Returns a CSS colour for the term (explicit override or deterministic slot).
   */
  public function color(?TermInterface $term): string {
    if ($term instanceof TermInterface
      && $term->hasField('field_color')
      && !$term->get('field_color')->isEmpty()) {
      $hex = trim((string) $term->get('field_color')->value);
      if (preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) {
        return strtolower($hex);
      }
    }
    return self::slot($term?->id());
  }

  /**
   * Returns the deterministic palette colour for a given id.
   */
  public static function slot(int|string|null $id): string {
    $n = (int) $id;
    return self::PALETTE[$n % count(self::PALETTE)];
  }

  /**
   * Returns the term's icon token (emoji / short string), or NULL.
   */
  public function icon(?TermInterface $term): ?string {
    if ($term instanceof TermInterface
      && $term->hasField('field_icon')
      && !$term->get('field_icon')->isEmpty()) {
      $icon = trim((string) $term->get('field_icon')->value);
      return $icon !== '' ? $icon : NULL;
    }
    return NULL;
  }

}
