<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenintra_theme\Kernel;

use Drupal\aabenintra_theme\PreferencesService;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Per-user preferences: defaults, allow-list validation and persistence.
 */
#[CoversClass(PreferencesService::class)]
#[Group('aabenintra_theme')]
final class PreferencesServiceTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'aabenintra_theme'];

  private PreferencesService $prefs;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->prefs = $this->container->get('aabenintra_theme.preferences');
  }

  /**
   * Anonymous users get the defaults and nothing is ever stored for them.
   */
  public function testAnonymousGetsDefaultsAndCannotSave(): void {
    $defaults = $this->prefs->defaults();
    $this->assertSame($defaults, $this->prefs->getAll());
    $this->assertSame($defaults, $this->prefs->setAll(['accent' => 'plum']));
    $this->assertSame('sky', $this->prefs->getAll()['accent']);
  }

  /**
   * Valid values persist across requests; unknown keys and values are dropped.
   */
  public function testSetAllValidatesAndPersists(): void {
    $user = $this->createUser();
    $this->setCurrentUser($user);

    $saved = $this->prefs->setAll([
      'accent' => 'custom',
      'accent_custom' => '#ABCDEF',
      'color_scheme' => 'dark',
      'density' => 'compact',
      'nav_collapsed' => '1',
      'tile_order' => ['12', 7, 0, 'x'],
      'tile_pinned' => [3, 3],
      // Not on the allow-list: must not be stored or emitted into the page.
      'onload' => 'alert(1)',
      'accent_bogus' => 'red',
    ]);

    $this->assertSame('custom', $saved['accent']);
    $this->assertSame('#abcdef', $saved['accent_custom'], 'Hex is normalised to lowercase.');
    $this->assertSame('dark', $saved['color_scheme']);
    $this->assertSame('compact', $saved['density']);
    $this->assertTrue($saved['nav_collapsed']);
    $this->assertSame([12, 7], $saved['tile_order'], 'Non-numeric and zero ids are dropped.');
    $this->assertSame([3, 3], $saved['tile_pinned']);
    $this->assertArrayNotHasKey('onload', $saved);
    $this->assertArrayNotHasKey('accent_bogus', $saved);

    // Read back through user.data, not the in-memory array.
    $this->assertSame($saved, $this->prefs->getAll($user));

    // Invalid values leave the stored ones untouched.
    $next = $this->prefs->setAll([
      'accent' => 'neon',
      'accent_custom' => 'javascript:',
      'color_scheme' => 'sepia',
      'density' => 'dense',
    ]);
    $this->assertSame('custom', $next['accent']);
    $this->assertSame('', $next['accent_custom'], 'A bad hex clears the custom colour.');
    $this->assertSame('dark', $next['color_scheme']);
    $this->assertSame('compact', $next['density']);
  }

  /**
   * Preferences are per account.
   */
  public function testPreferencesAreScopedToTheAccount(): void {
    $a = $this->createUser();
    $b = $this->createUser();
    $this->prefs->setAll(['accent' => 'forest'], $a);

    $this->assertSame('forest', $this->prefs->getAll($a)['accent']);
    $this->assertSame('sky', $this->prefs->getAll($b)['accent']);
  }

}
