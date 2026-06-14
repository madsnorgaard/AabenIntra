<?php

declare(strict_types=1);

namespace Drupal\aabenintra_channels\Controller;

use Drupal\aabenintra_channels\FollowService;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Channel directory, single-channel pages and the targeted news feed.
 */
final class ChannelsController extends ControllerBase {

  /**
   * Node bundles that carry channel topics.
   */
  private const BUNDLES = ['story', 'document'];

  public function __construct(
    private readonly FollowService $follow,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly CsrfTokenGenerator $csrfToken,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aabenintra_channels.follow'),
      $container->get('entity.repository'),
      $container->get('csrf_token'),
    );
  }

  /**
   * The channel directory: every topic with follow + post counts.
   */
  public function directory(): array {
    $uid = (int) $this->currentUser()->id();
    $termStorage = $this->entityTypeManager()->getStorage('taxonomy_term');
    $nodeStorage = $this->entityTypeManager()->getStorage('node');

    $channels = [];
    foreach ($termStorage->loadTree('topic', 0, NULL, TRUE) as $term) {
      $term = $this->entityRepository->getTranslationFromContext($term);
      $tid = (int) $term->id();
      $posts = (int) $nodeStorage->getQuery()
        ->accessCheck(TRUE)
        ->condition('status', 1)
        ->condition('type', self::BUNDLES, 'IN')
        ->condition('field_topics', $tid)
        ->count()
        ->execute();
      $channels[] = [
        'tid' => $tid,
        'name' => $term->label(),
        'url' => $term->toUrl()->toString(),
        'description' => $this->termDescription($term),
        'posts' => $posts,
        'followers' => $this->follow->followerCount(FollowService::TYPE_TOPIC, $tid),
        'following' => $this->follow->isFollowing($uid, FollowService::TYPE_TOPIC, $tid),
      ];
    }

    return [
      '#theme' => 'aabenintra_channels',
      '#channels' => $channels,
      '#attached' => $this->followAttachments(),
      '#cache' => $this->followCache($uid, ['taxonomy_term_list:topic', 'node_list']),
    ];
  }

  /**
   * A single channel: its description + tagged content.
   */
  public function channel(TermInterface $taxonomy_term): array {
    $uid = (int) $this->currentUser()->id();
    $term = $this->entityRepository->getTranslationFromContext($taxonomy_term);
    $tid = (int) $term->id();
    $followed = $this->follow->followingIds($uid);

    $nids = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', 1)
      ->condition('type', self::BUNDLES, 'IN')
      ->condition('field_topics', $tid)
      ->sort('created', 'DESC')
      ->pager(20)
      ->execute();

    return [
      '#theme' => 'aabenintra_channel',
      '#tid' => $tid,
      '#name' => $term->label(),
      '#description' => $this->termDescription($term),
      '#followers' => $this->follow->followerCount(FollowService::TYPE_TOPIC, $tid),
      '#following' => $this->follow->isFollowing($uid, FollowService::TYPE_TOPIC, $tid),
      '#cards' => $this->buildCards($nids, $followed),
      '#pager' => ['#type' => 'pager'],
      '#attached' => $this->followAttachments(),
      '#cache' => $this->followCache($uid, ['node_list', 'taxonomy_term:' . $tid]),
    ];
  }

  /**
   * Targeted news: followed channels plus org-wide fallback (never empty).
   */
  public function news(): array {
    $uid = (int) $this->currentUser()->id();
    $followed = $this->follow->followingIds($uid);
    $storage = $this->entityTypeManager()->getStorage('node');

    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', 1)
      ->condition('type', self::BUNDLES, 'IN');

    $outer = $query->orConditionGroup();
    // Org cascade: org-wide (no unit) or the user's unit + ancestors.
    $org = $query->orConditionGroup()->notExists('field_org_unit');
    $lineage = $this->userOrgLineage($uid);
    if ($lineage) {
      $org->condition('field_org_unit', $lineage, 'IN');
    }
    $outer->condition($org);
    // Followed channels, wherever posted.
    if ($followed) {
      $outer->condition('field_topics', $followed, 'IN');
    }
    $query->condition($outer);

    $nids = $query->sort('created', 'DESC')->pager(20)->execute();
    $cards = $this->buildCards($nids, $followed);

    return [
      '#theme' => 'aabenintra_news',
      '#cards' => $cards,
      '#empty' => $cards === [],
      '#pager' => ['#type' => 'pager'],
      '#attached' => $this->followAttachments(),
      '#cache' => $this->followCache($uid, ['node_list']),
    ];
  }

  /**
   * Builds card data for a set of node ids.
   *
   * @param array<int,int|string> $nids
   * @param array<int,int> $followed
   *
   * @return array<int,array<string,mixed>>
   */
  private function buildCards(array $nids, array $followed): array {
    $followedSet = array_flip(array_map('intval', $followed));
    $cards = [];
    foreach ($this->entityTypeManager()->getStorage('node')->loadMultiple($nids) as $node) {
      $node = $this->entityRepository->getTranslationFromContext($node);
      $topicTids = [];
      if ($node->hasField('field_topics')) {
        foreach ($node->get('field_topics') as $item) {
          $topicTids[] = (int) $item->target_id;
        }
      }
      $cards[] = [
        'title' => $node->label(),
        'url' => $node->toUrl()->toString(),
        'summary' => $this->summary($node),
        'eyebrow' => $this->eyebrow($node),
        'image_url' => $this->image($node),
        'following' => (bool) array_intersect_key($followedSet, array_flip($topicTids)),
      ];
    }
    return $cards;
  }

  /**
   * The user's org unit plus all ancestors (term ids).
   *
   * @return array<int,int>
   */
  private function userOrgLineage(int $uid): array {
    $user = $this->entityTypeManager()->getStorage('user')->load($uid);
    if ($user && $user->hasField('field_primary_org_unit') && !$user->get('field_primary_org_unit')->isEmpty()) {
      $tid = (int) $user->get('field_primary_org_unit')->target_id;
      $parents = $this->entityTypeManager()->getStorage('taxonomy_term')->loadAllParents($tid);
      return array_map('intval', array_keys($parents));
    }
    return [];
  }

  private function termDescription(TermInterface $term): string {
    if (!$term->get('description')->isEmpty()) {
      return trim(strip_tags((string) $term->get('description')->value));
    }
    return '';
  }

  private function summary(NodeInterface $node): string {
    if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
      $body = $node->get('body')->first();
      $raw = $body->summary ?: strip_tags((string) $body->value);
      return Unicode::truncate(trim($raw), 140, TRUE, TRUE);
    }
    return '';
  }

  private function eyebrow(NodeInterface $node): string {
    if ($node->hasField('field_department') && !$node->get('field_department')->isEmpty()) {
      $term = $node->get('field_department')->entity;
      return $term ? $term->label() : '';
    }
    return '';
  }

  private function image(NodeInterface $node): ?string {
    if (!$node->hasField('field_lead_image') || $node->get('field_lead_image')->isEmpty()) {
      return NULL;
    }
    $media = $node->get('field_lead_image')->entity;
    if ($media && $media->hasField('field_media_image') && !$media->get('field_media_image')->isEmpty()) {
      $file = $media->get('field_media_image')->entity;
      $style = $this->entityTypeManager()->getStorage('image_style')->load('tile_medium');
      if ($file && $style) {
        return $style->buildUrl($file->getFileUri());
      }
    }
    return NULL;
  }

  /**
   * Render #attached for the follow button (CSRF token + library).
   *
   * @return array<string,mixed>
   */
  private function followAttachments(): array {
    return [
      'library' => ['aabenintra_channels/follow'],
      'drupalSettings' => [
        'aabenintraChannels' => [
          'csrfToken' => $this->csrfToken->get('aabenintra_channels_follow'),
        ],
      ],
    ];
  }

  /**
   * Cache metadata for a follow-dependent page.
   *
   * @param array<int,string> $tags
   *
   * @return array<string,mixed>
   */
  private function followCache(int $uid, array $tags): array {
    return [
      'contexts' => ['user', 'url.query_args', 'languages:language_interface', 'languages:language_content'],
      'tags' => array_merge($tags, ['aabenintra_follow:' . $uid]),
    ];
  }

}
