<?php

declare(strict_types=1);

namespace Drupal\aabenintra_ops\Check\Isolation;

use Drupal\aabenintra_ops\Check\CheckResult;
use Drupal\aabenintra_ops\Check\HealthCheckInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * The Group access layer (intra-tenant workspace isolation) is active.
 *
 * Cross-tenant isolation is the container boundary; inside a tenant the
 * members-only workspaces rely on group + flexible_permissions (the chain
 * permission calculator). uid 1 does not bypass group access, which is why
 * install_prod.sh grants the administrator role the group overview perms.
 */
final class GroupAccessCheck implements HealthCheckInterface {

  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'group_access';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Group access policy';
  }

  /**
   * {@inheritdoc}
   */
  public function groups(): array {
    return [self::GROUP_ISOLATION];
  }

  /**
   * {@inheritdoc}
   */
  public function run(): CheckResult {
    foreach (['group', 'gnode', 'flexible_permissions', 'aabenintra_groups'] as $module) {
      if (!$this->moduleHandler->moduleExists($module)) {
        return CheckResult::fail(sprintf('Module %s is not enabled; workspace access control is inactive.', $module));
      }
    }
    $types = $this->entityTypeManager->hasDefinition('group_type')
      ? array_keys($this->entityTypeManager->getStorage('group_type')->loadMultiple())
      : [];
    if ($types === []) {
      return CheckResult::fail('No group types exist; aabenintra_groups hook_install did not run.');
    }
    return CheckResult::ok('Group access layer is active.', ['group_types' => array_map('strval', $types)]);
  }

}
