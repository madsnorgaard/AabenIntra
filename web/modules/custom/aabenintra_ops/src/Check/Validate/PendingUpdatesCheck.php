<?php

declare(strict_types=1);

namespace Drupal\aabenintra_ops\Check\Validate;

use Drupal\aabenintra_ops\Check\CheckResult;
use Drupal\aabenintra_ops\Check\HealthCheckInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Update\UpdateHookRegistry;

/**
 * No hook_update_N() may be pending (drush updb has run).
 */
final class PendingUpdatesCheck implements HealthCheckInterface {

  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly UpdateHookRegistry $updateHookRegistry,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'db_updates';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'No pending database updates';
  }

  /**
   * {@inheritdoc}
   */
  public function groups(): array {
    return [self::GROUP_VALIDATE];
  }

  /**
   * {@inheritdoc}
   */
  public function run(): CheckResult {
    $pending = [];
    foreach (array_keys($this->moduleHandler->getModuleList()) as $module) {
      $installed = $this->updateHookRegistry->getInstalledVersion($module);
      if ($installed === UpdateHookRegistry::SCHEMA_UNINSTALLED) {
        continue;
      }
      // hook_update_N() implementations live in .install files, which are not
      // loaded on a normal bootstrap.
      $this->moduleHandler->loadInclude($module, 'install');
      foreach ($this->updateHookRegistry->getAvailableUpdates($module) as $version) {
        if ($version > $installed && $this->updateHookRegistry->getEquivalentUpdate($module, $version) === NULL) {
          $pending[] = $module . '_update_' . $version;
        }
      }
    }
    if ($pending !== []) {
      return CheckResult::fail(sprintf('%d pending update hook(s); run drush updb.', count($pending)), ['pending' => $pending]);
    }
    return CheckResult::ok('No pending hook_update_N().');
  }

}
