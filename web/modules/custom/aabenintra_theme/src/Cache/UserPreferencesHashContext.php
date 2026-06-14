<?php

declare(strict_types=1);

namespace Drupal\aabenintra_theme\Cache;

use Drupal\aabenintra_theme\PreferencesService;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextInterface;

/**
 * Cache context keyed on a hash of the user's theme preferences.
 *
 * Users sharing the same accent/scheme/density share render-cache entries,
 * which avoids the per-user cache explosion a raw 'user' context would cause.
 */
final class UserPreferencesHashContext implements CacheContextInterface {

  public function __construct(
    private readonly PreferencesService $preferences,
  ) {}

  public static function getLabel(): string {
    return (string) t('AabenIntra user theme preferences');
  }

  public function getContext(): string {
    $prefs = $this->preferences->getAll();
    // Only the visual prefs affect rendering of the chrome; tile layout is
    // applied client-side and via the dashboard's own cache tags.
    $key = [
      $prefs['accent'] ?? '',
      $prefs['accent_custom'] ?? '',
      $prefs['color_scheme'] ?? '',
      $prefs['density'] ?? '',
    ];
    return hash('xxh3', implode('|', $key));
  }

  public function getCacheableMetadata(): CacheableMetadata {
    return new CacheableMetadata();
  }

}
