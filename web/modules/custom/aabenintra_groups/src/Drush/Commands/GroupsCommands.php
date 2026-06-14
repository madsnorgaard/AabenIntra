<?php

declare(strict_types=1);

namespace Drupal\aabenintra_groups\Drush\Commands;

use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for AabenIntra groups.
 */
final class GroupsCommands extends DrushCommands {

  /**
   * Finalises the Workspace group content plugins + member role.
   *
   * Run post-recipe: group relationship plugins create bundle fields that need
   * the group schema fully committed, which a recipe-time install cannot
   * guarantee. Idempotent.
   */
  #[CLI\Command(name: 'aabenintra_groups:install-plugins', aliases: ['ai-groups'])]
  public function installPlugins(): void {
    \Drupal::moduleHandler()->loadInclude('aabenintra_groups', 'install');
    aabenintra_groups_install_plugins();
    $this->logger()->success(dt('AabenIntra group content plugins are installed.'));
  }

}
