<?php

declare(strict_types=1);

namespace Drupal\aabenintra_activity\Controller;

use Drupal\aabenintra_activity\ActivityService;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Renders the organisation + group activity feed.
 */
final class ActivityFeedController extends ControllerBase {

  private const PER_PAGE = 30;

  public function __construct(
    private readonly ActivityService $activity,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly PagerManagerInterface $pagerManager,
    private readonly RequestStack $requestStack,
    private readonly TimeInterface $time,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aabenintra_activity.activity'),
      $container->get('entity.repository'),
      $container->get('date.formatter'),
      $container->get('pager.manager'),
      $container->get('request_stack'),
      $container->get('datetime.time'),
    );
  }

  /**
   * The feed page.
   */
  public function page(): array {
    $page = (int) $this->requestStack->getCurrentRequest()->query->get('page', 0);
    $page = max(0, $page);
    $offset = $page * self::PER_PAGE;

    // Followed channels widen the feed beyond org + groups. Resolved here so
    // this module stays independent of the channels module (optional dep).
    $uid = (int) $this->currentUser()->id();
    $extraNodeIds = [];
    if (\Drupal::hasService('aabenintra_channels.follow')) {
      $extraNodeIds = \Drupal::service('aabenintra_channels.follow')->nodeIdsForFollowedTopics($uid);
    }

    $result = $this->activity->feed($this->currentUser(), self::PER_PAGE, $offset, $extraNodeIds);
    $this->pagerManager->createPager($result['total'], self::PER_PAGE);

    return [
      '#theme' => 'aabenintra_activity_feed',
      '#groups' => $this->buildGroups($result['rows']),
      '#empty' => $result['total'] === 0,
      '#pager' => ['#type' => 'pager'],
      '#attached' => ['library' => ['aabenintra/global']],
      '#cache' => [
        'tags' => ['aabenintra_activity', 'node_list', 'aabenintra_follow:' . $uid],
        // 'user' (not just the org/group hash) because the feed now also varies
        // by the user's personal channel follows.
        'contexts' => [
          'user',
          'url.query_args:page',
          'languages:language_interface',
          'languages:language_content',
        ],
      ],
    ];
  }

  /**
   * Groups feed rows under day headings (Today / Yesterday / date).
   *
   * @param array<int,object> $rows
   *
   * @return array<int,array<string,mixed>>
   */
  private function buildGroups(array $rows): array {
    $today = $this->dayKey($this->time->getRequestTime());
    $yesterday = $this->dayKey($this->time->getRequestTime() - 86400);

    $groups = [];
    foreach ($rows as $row) {
      $item = $this->buildItem($row);
      if ($item === NULL) {
        continue;
      }
      $key = $this->dayKey((int) $row->created);
      if (!isset($groups[$key])) {
        $label = match ($key) {
          $today => $this->t('Today'),
          $yesterday => $this->t('Yesterday'),
          default => $this->dateFormatter->format((int) $row->created, 'custom', 'j F Y'),
        };
        $groups[$key] = ['label' => $label, 'items' => []];
      }
      $groups[$key]['items'][] = $item;
    }
    return array_values($groups);
  }

  /**
   * A site-timezone day bucket key (Y-m-d) for a timestamp.
   */
  private function dayKey(int $timestamp): string {
    return $this->dateFormatter->format($timestamp, 'custom', 'Y-m-d');
  }

  /**
   * Builds one render-ready feed item, or NULL if the subject is gone/denied.
   *
   * @return array<string,mixed>|null
   */
  private function buildItem(object $row): ?array {
    $node = $this->entityTypeManager()->getStorage('node')->load((int) $row->entity_id);
    if (!$node instanceof NodeInterface) {
      return NULL;
    }
    $node = $this->entityRepository->getTranslationFromContext($node);
    if (!$node->access('view')) {
      return NULL;
    }

    $actor = $this->entityTypeManager()->getStorage('user')->load((int) $row->actor_uid);
    $bundle = $row->bundle ?: $node->bundle();

    $item = [
      'verb' => $row->verb,
      'action' => $this->action((string) $row->verb),
      'actor' => $this->person($actor instanceof UserInterface ? $actor : NULL),
      'subject_title' => $node->label(),
      'subject_url' => $node->toUrl()->toString(),
      'bundle' => $bundle,
      'bundle_label' => $this->bundleLabel($bundle),
      'recipient' => NULL,
      'time' => $this->dateFormatter->format((int) $row->created, 'custom', 'H:i'),
    ];

    if ($row->verb === 'kudos' && !empty($row->target_uid)) {
      $recipient = $this->entityTypeManager()->getStorage('user')->load((int) $row->target_uid);
      $item['recipient'] = $this->person($recipient instanceof UserInterface ? $recipient : NULL);
    }

    return $item;
  }

  /**
   * The translated verb phrase placed after the actor's name.
   */
  private function action(string $verb): string {
    return (string) match ($verb) {
      'published' => $this->t('published'),
      'commented' => $this->t('commented on'),
      'kudos' => $this->t('gave kudos to'),
      default => $this->t('updated'),
    };
  }

  /**
   * A short, translated label for a content bundle.
   */
  private function bundleLabel(string $bundle): string {
    return (string) match ($bundle) {
      'story' => $this->t('News'),
      'event' => $this->t('Event'),
      'document' => $this->t('Document'),
      'tip' => $this->t('Tip'),
      'kudos' => $this->t('Kudos'),
      default => ucfirst($bundle),
    };
  }

  /**
   * Render-ready data for an actor/recipient (name, link, avatar, initial).
   *
   * @return array<string,mixed>
   */
  private function person(?UserInterface $user): array {
    if (!$user || $user->isAnonymous()) {
      return ['name' => $this->t('Someone'), 'url' => NULL, 'initial' => '?', 'photo' => NULL];
    }
    $name = $user->getDisplayName();
    $photo = NULL;
    if ($user->hasField('field_photo') && !$user->get('field_photo')->isEmpty()) {
      $file = $user->get('field_photo')->entity;
      $style = $this->entityTypeManager()->getStorage('image_style')->load('thumbnail');
      if ($file && $style) {
        $photo = $style->buildUrl($file->getFileUri());
      }
    }
    return [
      'name' => $name,
      'url' => $user->toUrl()->toString(),
      'initial' => mb_strtoupper(mb_substr($name, 0, 1)),
      'photo' => $photo,
    ];
  }

}
