<?php

declare(strict_types=1);

namespace Drupal\aabenintra_activity\Drush\Commands;

use Drupal\aabenintra_activity\ActivityService;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for the AabenIntra activity feed.
 */
final class ActivityCommands extends DrushCommands {

  public function __construct(
    private readonly ActivityService $activity,
  ) {
    parent::__construct();
  }

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aabenintra_activity.activity'),
    );
  }

  /**
   * Rebuilds the activity log from existing content (idempotent).
   */
  #[CLI\Command(name: 'aabenintra_activity:backfill', aliases: ['ai-activity-backfill'])]
  #[CLI\Usage(name: 'drush aabenintra_activity:backfill', description: 'Populate the feed from existing nodes, comments and kudos.')]
  public function backfill(): void {
    $counts = $this->activity->backfill();
    foreach ($counts as $verb => $count) {
      $this->logger()->success(dt('Logged @count @verb events.', ['@count' => $count, '@verb' => $verb]));
    }
    $this->logger()->success(dt('Activity backfill complete.'));
  }

}
