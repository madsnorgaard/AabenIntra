<?php

declare(strict_types=1);

namespace Drupal\aabenintra_theme;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\UserDataInterface;

/**
 * Reads and writes per-user AabenIntra theme preferences (in user.data).
 *
 * Modelled on Gin's GinSettings: a fallback chain of user override -> defaults,
 * with a strict allow-list so only known, validated values are ever stored or
 * emitted into the page.
 */
final class PreferencesService {

  private const MODULE = 'aabenintra_theme';
  private const NAME = 'prefs';

  public const ACCENTS = ['clay', 'rust', 'gold', 'forest', 'teal', 'ocean', 'ink', 'plum', 'custom'];
  public const SCHEMES = ['light', 'dark', 'auto'];
  public const DENSITIES = ['comfortable', 'compact'];

  public function __construct(
    private readonly UserDataInterface $userData,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Built-in defaults (tenant theme settings can override these later).
   *
   * @return array<string,mixed>
   */
  public function defaults(): array {
    return [
      'accent' => 'clay',
      'accent_custom' => '',
      'color_scheme' => 'auto',
      'density' => 'comfortable',
      'nav_collapsed' => FALSE,
      'tile_order' => [],
      'tile_pinned' => [],
    ];
  }

  /**
   * Returns the effective preferences for an account (defaults + overrides).
   *
   * @return array<string,mixed>
   */
  public function getAll(?AccountInterface $account = NULL): array {
    $account ??= $this->currentUser;
    $stored = [];
    if ($account->isAuthenticated()) {
      $stored = $this->userData->get(self::MODULE, (int) $account->id(), self::NAME) ?: [];
    }
    return array_merge($this->defaults(), is_array($stored) ? $stored : []);
  }

  /**
   * Merges a validated patch into the stored preferences.
   *
   * @param array<string,mixed> $patch
   *
   * @return array<string,mixed>
   *   The new effective preferences.
   */
  public function setAll(array $patch, ?AccountInterface $account = NULL): array {
    $account ??= $this->currentUser;
    if ($account->isAnonymous()) {
      return $this->getAll($account);
    }
    $current = $this->getAll($account);
    $clean = $this->sanitize($patch);
    $next = array_merge($current, $clean);
    $this->userData->set(self::MODULE, (int) $account->id(), self::NAME, $next);
    return $next;
  }

  /**
   * Allow-list + value validation. Unknown keys/values are dropped.
   *
   * @param array<string,mixed> $patch
   *
   * @return array<string,mixed>
   */
  private function sanitize(array $patch): array {
    $out = [];
    if (isset($patch['accent']) && in_array($patch['accent'], self::ACCENTS, TRUE)) {
      $out['accent'] = $patch['accent'];
    }
    if (array_key_exists('accent_custom', $patch)) {
      $hex = (string) $patch['accent_custom'];
      $out['accent_custom'] = preg_match('/^#[0-9a-fA-F]{6}$/', $hex) ? strtolower($hex) : '';
    }
    if (isset($patch['color_scheme']) && in_array($patch['color_scheme'], self::SCHEMES, TRUE)) {
      $out['color_scheme'] = $patch['color_scheme'];
    }
    if (isset($patch['density']) && in_array($patch['density'], self::DENSITIES, TRUE)) {
      $out['density'] = $patch['density'];
    }
    if (array_key_exists('nav_collapsed', $patch)) {
      $out['nav_collapsed'] = (bool) $patch['nav_collapsed'];
    }
    if (isset($patch['tile_order']) && is_array($patch['tile_order'])) {
      $out['tile_order'] = array_values(array_filter(array_map('intval', $patch['tile_order'])));
    }
    if (isset($patch['tile_pinned']) && is_array($patch['tile_pinned'])) {
      $out['tile_pinned'] = array_values(array_filter(array_map('intval', $patch['tile_pinned'])));
    }
    return $out;
  }

}
