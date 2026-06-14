<?php

declare(strict_types=1);

namespace Drupal\aabenintra_demo\Drush\Commands;

use Drupal\aabenintra_demo\DemoContentGenerator;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands to seed and clear AabenIntra demo content.
 */
final class DemoCommands extends DrushCommands {

  public function __construct(
    private readonly DemoContentGenerator $generator,
  ) {
    parent::__construct();
  }

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aabenintra_demo.generator'),
    );
  }

  /**
   * Seeds AabenIntra demo intranet content (dev/staging only).
   */
  #[CLI\Command(name: 'aabenintra:demo-seed', aliases: ['ai-seed'])]
  #[CLI\Usage(name: 'drush aabenintra:demo-seed', description: 'Create demo departments, topics, users, media and stories.')]
  public function seed(): void {
    $counts = $this->generator->seed();
    foreach ($counts as $type => $count) {
      $this->logger()->success(dt('Created @count @type.', ['@count' => $count, '@type' => $type]));
    }
    $this->logger()->success(dt('Demo content seeded.'));
  }

  /**
   * Removes all AabenIntra demo content created by demo-seed.
   */
  #[CLI\Command(name: 'aabenintra:demo-clear', aliases: ['ai-clear'])]
  #[CLI\Usage(name: 'drush aabenintra:demo-clear', description: 'Delete all previously seeded demo content.')]
  public function clear(): void {
    $counts = $this->generator->clear();
    foreach ($counts as $type => $count) {
      $this->logger()->success(dt('Removed @count @type.', ['@count' => $count, '@type' => $type]));
    }
    $this->logger()->success(dt('Demo content cleared.'));
  }

}
