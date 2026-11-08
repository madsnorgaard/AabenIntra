<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenintra_dashboard\Kernel;

use Drupal\aabenintra_dashboard\Controller\DashboardController;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Renders the dashboard controller for a signed-in user.
 *
 * Runs the real entity query, visibility scoping, tile building and Twig
 * template without a web server. Route-level access is covered by the
 * Functional test.
 */
#[CoversClass(DashboardController::class)]
#[Group('aabenintra_dashboard')]
final class DashboardControllerTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'file',
    'image',
    'link',
    'menu_link_content',
    'aabenintra_theme',
    'aabenintra_dashboard',
  ];

  private UserInterface $reader;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['system', 'filter', 'node']);

    NodeType::create(['type' => 'story', 'name' => 'Story'])->save();
    NodeType::create(['type' => 'document', 'name' => 'Document'])->save();

    // Routes are needed for $node->toUrl() inside the tiles.
    $this->container->get('router.builder')->rebuild();

    $this->reader = $this->createUser(['access content'], 'kirsten');
    $this->setCurrentUser($this->reader);
  }

  /**
   * Published stories become tiles; drafts and other bundles do not.
   */
  public function testPageRendersStoriesForAuthenticatedUser(): void {
    $this->createStory('Budget 2027 vedtaget', TRUE);
    $this->createStory('Kladde: ny kantineordning', FALSE);
    Node::create([
      'type' => 'document',
      'title' => 'Personalehaandbog',
      'status' => 1,
      'uid' => $this->reader->id(),
    ])->save();

    $html = $this->render();

    $this->assertStringContainsString('data-ai-dashboard', $html);
    $this->assertStringContainsString('kirsten', $html, 'Greeting carries the display name.');
    $this->assertStringContainsString('Budget 2027 vedtaget', $html);
    $this->assertStringNotContainsString('Kladde: ny kantineordning', $html, 'Unpublished stories are hidden.');
    $this->assertStringNotContainsString('Personalehaandbog', $html, 'Only the story bundle feeds the dashboard.');
    // Without a lead image or pin, "auto" sizing resolves to small.
    $this->assertStringContainsString('tile--small', $html);
  }

  /**
   * A user-pinned story is flagged and sorted first.
   */
  public function testPinnedStoriesComeFirst(): void {
    $older = $this->createStory('Aeldre nyhed', TRUE, 1_700_000_000);
    $this->createStory('Nyeste nyhed', TRUE, 1_700_100_000);
    $this->container->get('aabenintra_theme.preferences')->setAll(['tile_pinned' => [(int) $older->id()]]);

    $build = DashboardController::create($this->container)->page();

    $this->assertCount(2, $build['#tiles']);
    $this->assertSame('Aeldre nyhed', $build['#tiles'][0]['title']);
    $this->assertTrue($build['#tiles'][0]['pinned']);
    $this->assertSame('large', $build['#tiles'][0]['size'], 'Pinned tiles are promoted to large.');
    $this->assertFalse($build['#tiles'][1]['pinned']);
    $this->assertContains('user', $build['#cache']['contexts']);
    $this->assertContains('node_list:story', $build['#cache']['tags']);
  }

  /**
   * An empty intranet still renders, with the empty-state copy.
   */
  public function testEmptyState(): void {
    $html = $this->render();
    $this->assertStringContainsString('No stories yet.', $html);
  }

  private function createStory(string $title, bool $published, ?int $created = NULL): Node {
    $node = Node::create([
      'type' => 'story',
      'title' => $title,
      'status' => $published ? 1 : 0,
      'uid' => $this->reader->id(),
      'created' => $created ?? \Drupal::time()->getRequestTime(),
    ]);
    $node->save();
    return $node;
  }

  private function render(): string {
    $build = DashboardController::create($this->container)->page();
    return (string) $this->container->get('renderer')->renderRoot($build);
  }

}
