<?php

declare(strict_types=1);

namespace Drupal\aabenintra_dashboard\Controller;

use Drupal\aabenintra_theme\PreferencesService;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the personalized intranet tile dashboard.
 */
final class DashboardController extends ControllerBase {

  public function __construct(
    private readonly PreferencesService $preferences,
    private readonly EntityRepositoryInterface $entityRepository,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aabenintra_theme.preferences'),
      $container->get('entity.repository'),
    );
  }

  /**
   * The dashboard page.
   */
  public function page(): array {
    $storage = $this->entityTypeManager()->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'story')
      ->condition('status', 1);

    // Visibility: org-wide posts (no unit) + posts targeting the user's unit or
    // any of its ancestors (a division post reaches its teams), PLUS stories in
    // any channel (topic) the user follows - wherever they are posted.
    $account = $this->entityTypeManager()->getStorage('user')->load($this->currentUser()->id());
    $scope = $query->orConditionGroup();
    $scoped = FALSE;
    if ($account && $account->hasField('field_primary_org_unit') && !$account->get('field_primary_org_unit')->isEmpty()) {
      $unit_tid = (int) $account->get('field_primary_org_unit')->target_id;
      $unit_ids = array_map('intval', array_keys($this->entityTypeManager()->getStorage('taxonomy_term')->loadAllParents($unit_tid)));
      $org = $query->orConditionGroup()
        ->notExists('field_org_unit')
        ->condition('field_org_unit', $unit_ids, 'IN');
      $scope->condition($org);
      $scoped = TRUE;
    }
    // Followed channels (defensive: the channels module may not be installed).
    if (\Drupal::hasService('aabenintra_channels.follow')) {
      $followed = \Drupal::service('aabenintra_channels.follow')->followingIds((int) $this->currentUser()->id());
      if ($followed) {
        $scope->condition('field_topics', $followed, 'IN');
        $scoped = TRUE;
      }
    }
    if ($scoped) {
      $query->condition($scope);
    }

    $ids = $query->sort('created', 'DESC')->range(0, 30)->execute();
    $nodes = $storage->loadMultiple($ids);

    // Per-user personalisation: pins and explicit ordering.
    $prefs = $this->preferences->getAll();
    $userPinned = array_flip(array_map('intval', $prefs['tile_pinned'] ?? []));
    $orderPos = array_flip(array_map('intval', $prefs['tile_order'] ?? []));

    $isPinned = static fn(NodeInterface $n): bool =>
      isset($userPinned[(int) $n->id()])
      || (bool) ($n->hasField('field_pinned') ? $n->get('field_pinned')->value : FALSE);

    // Order: pinned first, then the user's saved order, then most recent.
    uasort($nodes, static function (NodeInterface $a, NodeInterface $b) use ($isPinned, $orderPos): int {
      $pa = (int) $isPinned($a);
      $pb = (int) $isPinned($b);
      if ($pa !== $pb) {
        return $pb <=> $pa;
      }
      $oa = $orderPos[(int) $a->id()] ?? PHP_INT_MAX;
      $ob = $orderPos[(int) $b->id()] ?? PHP_INT_MAX;
      if ($oa !== $ob) {
        return $oa <=> $ob;
      }
      return (int) $b->get('created')->value <=> (int) $a->get('created')->value;
    });

    $tiles = [];
    foreach ($nodes as $node) {
      $tiles[] = $this->buildTile($node, $isPinned($node));
    }

    return [
      '#theme' => 'aabenintra_dashboard',
      '#greeting' => $this->greeting(),
      '#tiles' => $tiles,
      '#attached' => ['library' => ['aabenintra/global']],
      '#cache' => [
        'tags' => ['node_list:story', 'aabenintra_follow:' . (int) $this->currentUser()->id()],
        'contexts' => ['user', 'languages:language_content', 'languages:language_interface'],
      ],
    ];
  }

  /**
   * Builds the render-ready data for one Story tile.
   *
   * @return array<string,mixed>
   */
  private function buildTile(NodeInterface $node, bool $pinned): array {
    // Render each Story in the current content language (EN/DA) when translated.
    $node = $this->entityRepository->getTranslationFromContext($node);
    $size = $node->hasField('field_tile_size') ? ($node->get('field_tile_size')->value ?: 'auto') : 'auto';

    $image_url = NULL;
    $image_alt = '';
    if ($node->hasField('field_lead_image') && !$node->get('field_lead_image')->isEmpty()) {
      $media = $node->get('field_lead_image')->entity;
      if ($media && $media->hasField('field_media_image') && !$media->get('field_media_image')->isEmpty()) {
        $file = $media->get('field_media_image')->entity;
        $image_alt = (string) ($media->get('field_media_image')->alt ?? $node->label());
        if ($file) {
          $style_id = ($size === 'large') ? 'tile_large' : 'tile_medium';
          $style = $this->entityTypeManager()->getStorage('image_style')->load($style_id);
          if ($style) {
            $image_url = $style->buildUrl($file->getFileUri());
          }
        }
      }
    }

    // Resolve "auto" sizing once, server-side, so it matches the island.
    if ($size === 'auto') {
      $size = $pinned ? 'large' : ($image_url ? 'medium' : 'small');
    }

    $summary = '';
    if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
      $body = $node->get('body')->first();
      $raw = $body->summary ?: strip_tags((string) $body->value);
      $summary = Unicode::truncate(trim($raw), 140, TRUE, TRUE);
    }

    $department = '';
    if ($node->hasField('field_department') && !$node->get('field_department')->isEmpty()) {
      $term = $node->get('field_department')->entity;
      $department = $term ? $term->label() : '';
    }

    return [
      'id' => (int) $node->id(),
      'title' => $node->label(),
      'url' => $node->toUrl()->toString(),
      'summary' => $summary,
      'image_url' => $image_url,
      'image_alt' => $image_alt,
      'eyebrow' => $department,
      'size' => $size,
      'pinned' => $pinned,
    ];
  }

  /**
   * A time-of-day greeting with the user's name.
   */
  private function greeting(): string {
    $hour = (int) (new DrupalDateTime())->format('G');
    $part = match (TRUE) {
      $hour < 12 => $this->t('Good morning'),
      $hour < 18 => $this->t('Good afternoon'),
      default => $this->t('Good evening'),
    };
    return (string) $this->t('@part, @name', [
      '@part' => $part,
      '@name' => $this->currentUser()->getDisplayName(),
    ]);
  }

}
