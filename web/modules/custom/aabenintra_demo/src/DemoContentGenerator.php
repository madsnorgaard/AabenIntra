<?php

declare(strict_types=1);

namespace Drupal\aabenintra_demo;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;

/**
 * Creates and removes self-contained demo intranet content.
 *
 * All entities created by seed() are tracked in state so clear() can remove
 * exactly what was added, leaving the rest of the site untouched. This module
 * is intended for dev and staging only.
 */
final class DemoContentGenerator {

  /**
   * State key holding the ids of entities created by this module.
   */
  private const STATE_KEY = 'aabenintra_demo.created';

  /**
   * Demo departments: label => [hero RGB, brand colour (hex)].
   *
   * The hex is written to each term's field_color so the showcase shows distinct,
   * fresh category colours; the RGB drives the generated hero-image background.
   * Icons are left to editors (field_icon) since emoji glyphs render unevenly
   * across clients - the colour dot is the robust default.
   */
  private const DEPARTMENTS = [
    'People & Culture' => ['rgb' => [13, 148, 136], 'color' => '#0d9488'],
    'IT & Digital' => ['rgb' => [37, 99, 235], 'color' => '#2563eb'],
    'Finance' => ['rgb' => [124, 58, 237], 'color' => '#7c3aed'],
    'Marketing' => ['rgb' => [219, 39, 119], 'color' => '#db2777'],
    'Operations' => ['rgb' => [217, 119, 6], 'color' => '#d97706'],
  ];

  private const TOPICS = [
    'Announcements',
    'Policies',
    'Events',
    'How-to',
    'Wins',
    'People',
  ];

  private const AUDIENCES = [
    'Everyone',
    'Managers',
    'New starters',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileSystemInterface $fileSystem,
    private readonly StateInterface $state,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Seeds demo taxonomy, users, media and Story content.
   *
   * @return array<string,int>
   *   Counts of created entities, keyed by entity type id.
   */
  public function seed(): array {
    $created = $this->emptyLedger();

    $deptAttributes = [];
    foreach (self::DEPARTMENTS as $name => $meta) {
      $deptAttributes[$name] = ['field_color' => $meta['color']];
    }
    $departments = $this->createTerms('department', array_keys(self::DEPARTMENTS), $created, $deptAttributes);
    $topics = $this->createTerms('topic', self::TOPICS, $created);
    $audiences = $this->createTerms('audience', self::AUDIENCES, $created);
    $authors = $this->createUsers($created);

    foreach ($this->stories() as $i => $story) {
      $deptName = $story['department'];
      $mediaId = NULL;
      if ($story['tile'] !== 'small') {
        $mediaId = $this->createImageMedia($story['title'], self::DEPARTMENTS[$deptName]['rgb'], $created);
      }
      $values = [
        'type' => 'story',
        'title' => $story['title'],
        'uid' => $authors[$i % count($authors)],
        'status' => 1,
        'body' => [
          'value' => '<p>' . $story['body'] . '</p>',
          'format' => 'basic_html',
        ],
        'field_department' => ['target_id' => $departments[$deptName]],
        'field_topics' => array_map(
          static fn(string $t): array => ['target_id' => $topics[$t]],
          $story['topics'],
        ),
        'field_audience' => ['target_id' => $audiences[$story['audience']]],
        'field_tile_size' => $story['tile'],
        'field_pinned' => $story['pinned'] ? 1 : 0,
      ];
      if ($mediaId !== NULL) {
        $values['field_lead_image'] = ['target_id' => $mediaId];
      }
      $node = $this->entityTypeManager->getStorage('node')->create($values);
      $node->save();
      $this->track($created, 'node', (int) $node->id());
    }

    $this->state->set(self::STATE_KEY, $created);
    $counts = array_map('count', $created);
    $this->loggerFactory->get('aabenintra_demo')->notice(
      'Seeded demo content: @counts',
      ['@counts' => http_build_query($counts, '', ', ')],
    );
    return $counts;
  }

  /**
   * Removes all content previously created by seed().
   *
   * @return array<string,int>
   *   Counts of removed entities, keyed by entity type id.
   */
  public function clear(): array {
    $ledger = $this->state->get(self::STATE_KEY, $this->emptyLedger());
    $removed = $this->emptyLedger();
    // Delete in dependency-safe order: content first, then media/files, then
    // terms and users.
    foreach (['node', 'media', 'file', 'taxonomy_term', 'user'] as $entityTypeId) {
      $ids = $ledger[$entityTypeId] ?? [];
      if (!$ids) {
        continue;
      }
      $storage = $this->entityTypeManager->getStorage($entityTypeId);
      $entities = $storage->loadMultiple($ids);
      if ($entities) {
        $storage->delete($entities);
        $removed[$entityTypeId] = array_values(array_map(
          static fn(EntityInterface $e): int => (int) $e->id(),
          $entities,
        ));
      }
    }
    $this->state->delete(self::STATE_KEY);
    return array_map('count', $removed);
  }

  /**
   * Creates taxonomy terms in a vocabulary.
   *
   * @return array<string,int>
   *   Term name => term id.
   */
  private function createTerms(string $vid, array $names, array &$ledger, array $attributes = []): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $map = [];
    foreach ($names as $name) {
      $term = $storage->create(['vid' => $vid, 'name' => $name]);
      foreach (($attributes[$name] ?? []) as $field => $value) {
        if ($term->hasField($field)) {
          $term->set($field, $value);
        }
      }
      $term->save();
      $map[$name] = (int) $term->id();
      $this->track($ledger, 'taxonomy_term', (int) $term->id());
    }
    return $map;
  }

  /**
   * Creates a few demo authors.
   *
   * @return int[]
   *   The created user ids.
   */
  private function createUsers(array &$ledger): array {
    $storage = $this->entityTypeManager->getStorage('user');
    $people = [
      ['name' => 'demo.anna', 'mail' => 'anna@example.test', 'display' => 'Anna Madsen'],
      ['name' => 'demo.jonas', 'mail' => 'jonas@example.test', 'display' => 'Jonas Berg'],
      ['name' => 'demo.priya', 'mail' => 'priya@example.test', 'display' => 'Priya Shah'],
    ];
    $ids = [];
    foreach ($people as $person) {
      $user = $storage->create([
        'name' => $person['name'],
        'mail' => $person['mail'],
        'status' => 1,
        'pass' => 'demo',
      ]);
      $user->save();
      $ids[] = (int) $user->id();
      $this->track($ledger, 'user', (int) $user->id());
    }
    return $ids;
  }

  /**
   * Generates a hero image and wraps it in an image media entity.
   *
   * @return int|null
   *   The media id, or NULL if image generation is unavailable.
   */
  private function createImageMedia(string $title, array $rgb, array &$ledger): ?int {
    if (!function_exists('imagecreatetruecolor')) {
      return NULL;
    }
    $dir = 'public://aabenintra-demo';
    $this->fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY);

    $width = 1200;
    $height = 800;
    $image = imagecreatetruecolor($width, $height);
    $base = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
    $dark = imagecolorallocate($image, (int) ($rgb[0] * 0.6), (int) ($rgb[1] * 0.6), (int) ($rgb[2] * 0.6));
    $white = imagecolorallocate($image, 255, 255, 255);
    imagefilledrectangle($image, 0, 0, $width, $height, $base);
    imagefilledrectangle($image, 0, (int) ($height * 0.62), $width, $height, $dark);
    // Title text using the built-in large font.
    imagestring($image, 5, 48, (int) ($height * 0.7), substr($title, 0, 60), $white);
    imagestring($image, 3, 48, (int) ($height * 0.7) + 24, 'AabenIntra demo', $white);

    $tmp = $this->fileSystem->tempnam('temporary://', 'aidemo') . '.jpg';
    imagejpeg($image, $this->fileSystem->realpath($tmp), 85);
    imagedestroy($image);

    $data = file_get_contents($this->fileSystem->realpath($tmp));
    $destination = $dir . '/' . preg_replace('/[^a-z0-9]+/', '-', strtolower($title)) . '-' . substr(md5($title), 0, 6) . '.jpg';
    $uri = $this->fileSystem->saveData($data, $destination, FileExists::Replace);

    $file = $this->entityTypeManager->getStorage('file')->create([
      'uri' => $uri,
      'status' => 1,
    ]);
    $file->save();
    $this->track($ledger, 'file', (int) $file->id());

    $media = $this->entityTypeManager->getStorage('media')->create([
      'bundle' => 'image',
      'name' => $title,
      'status' => 1,
      'field_media_image' => [
        'target_id' => $file->id(),
        'alt' => $title,
      ],
    ]);
    $media->save();
    $this->track($ledger, 'media', (int) $media->id());

    return (int) $media->id();
  }

  /**
   * The curated demo stories.
   *
   * @return array<int,array<string,mixed>>
   */
  private function stories(): array {
    return [
      ['title' => 'Welcome to the new AabenIntra', 'department' => 'People & Culture', 'topics' => ['Announcements'], 'audience' => 'Everyone', 'tile' => 'large', 'pinned' => TRUE, 'body' => 'Our new intranet is live. Find news, documents and your teams all in one place.'],
      ['title' => 'Q3 all-hands: save the date', 'department' => 'People & Culture', 'topics' => ['Events', 'Announcements'], 'audience' => 'Everyone', 'tile' => 'medium', 'pinned' => FALSE, 'body' => 'Join the company-wide meeting next month. Agenda and dial-in to follow.'],
      ['title' => 'Updated remote-work policy', 'department' => 'People & Culture', 'topics' => ['Policies'], 'audience' => 'Everyone', 'tile' => 'small', 'pinned' => FALSE, 'body' => 'The hybrid working policy has been refreshed for the new year.'],
      ['title' => 'New laptop rollout begins', 'department' => 'IT & Digital', 'topics' => ['Announcements', 'How-to'], 'audience' => 'Everyone', 'tile' => 'medium', 'pinned' => FALSE, 'body' => 'Hardware refresh is underway. Check your eligibility and booking slot.'],
      ['title' => 'How to set up MFA in five minutes', 'department' => 'IT & Digital', 'topics' => ['How-to', 'Policies'], 'audience' => 'Everyone', 'tile' => 'small', 'pinned' => FALSE, 'body' => 'Multi-factor authentication is now required. Step-by-step guide inside.'],
      ['title' => 'Service desk wins service award', 'department' => 'IT & Digital', 'topics' => ['Wins'], 'audience' => 'Everyone', 'tile' => 'large', 'pinned' => FALSE, 'body' => 'Our support team was recognised for outstanding response times this quarter.'],
      ['title' => 'Expense system migration', 'department' => 'Finance', 'topics' => ['Announcements', 'How-to'], 'audience' => 'Everyone', 'tile' => 'medium', 'pinned' => FALSE, 'body' => 'We are moving to a new expenses platform. Training sessions open for booking.'],
      ['title' => 'Year-end close timeline', 'department' => 'Finance', 'topics' => ['Policies'], 'audience' => 'Managers', 'tile' => 'small', 'pinned' => FALSE, 'body' => 'Key dates for budget owners ahead of the financial year end.'],
      ['title' => 'Brand refresh is here', 'department' => 'Marketing', 'topics' => ['Announcements', 'Wins'], 'audience' => 'Everyone', 'tile' => 'large', 'pinned' => TRUE, 'body' => 'New logo, palette and templates are available in the brand centre.'],
      ['title' => 'Campaign results: spring launch', 'department' => 'Marketing', 'topics' => ['Wins'], 'audience' => 'Everyone', 'tile' => 'medium', 'pinned' => FALSE, 'body' => 'The spring campaign beat its targets. Read the highlights and learnings.'],
      ['title' => 'Photo guidelines updated', 'department' => 'Marketing', 'topics' => ['Policies', 'How-to'], 'audience' => 'Everyone', 'tile' => 'small', 'pinned' => FALSE, 'body' => 'Refreshed guidance on imagery and accessibility for all channels.'],
      ['title' => 'Warehouse safety week', 'department' => 'Operations', 'topics' => ['Events', 'Policies'], 'audience' => 'Everyone', 'tile' => 'medium', 'pinned' => FALSE, 'body' => 'A week of refreshers and drills across all sites. Schedule attached.'],
      ['title' => 'New supplier onboarding portal', 'department' => 'Operations', 'topics' => ['Announcements', 'How-to'], 'audience' => 'Managers', 'tile' => 'small', 'pinned' => FALSE, 'body' => 'Faster, self-service onboarding for partners goes live this month.'],
      ['title' => 'Meet the new joiners', 'department' => 'People & Culture', 'topics' => ['People'], 'audience' => 'Everyone', 'tile' => 'medium', 'pinned' => FALSE, 'body' => 'Say hello to the people who joined us across teams this month.'],
      ['title' => 'Volunteering day photos', 'department' => 'People & Culture', 'topics' => ['Wins', 'People'], 'audience' => 'Everyone', 'tile' => 'large', 'pinned' => FALSE, 'body' => 'A look back at our community volunteering day. Thank you to everyone who took part.'],
      ['title' => 'Onboarding checklist for new starters', 'department' => 'People & Culture', 'topics' => ['How-to'], 'audience' => 'New starters', 'tile' => 'small', 'pinned' => FALSE, 'body' => 'Everything you need in your first two weeks, in one place.'],
      ['title' => 'Data classification refresher', 'department' => 'IT & Digital', 'topics' => ['Policies'], 'audience' => 'Everyone', 'tile' => 'small', 'pinned' => FALSE, 'body' => 'A quick reminder of how we label and handle information.'],
      ['title' => 'Office move: phase two', 'department' => 'Operations', 'topics' => ['Announcements', 'Events'], 'audience' => 'Everyone', 'tile' => 'medium', 'pinned' => FALSE, 'body' => 'The next phase of the office move starts soon. What to expect and when.'],
    ];
  }

  /**
   * Returns an empty ledger structure.
   *
   * @return array<string,int[]>
   */
  private function emptyLedger(): array {
    return [
      'node' => [],
      'media' => [],
      'file' => [],
      'taxonomy_term' => [],
      'user' => [],
    ];
  }

  /**
   * Records a created entity id in the ledger.
   */
  private function track(array &$ledger, string $entityTypeId, int $id): void {
    $ledger[$entityTypeId][] = $id;
  }

}
