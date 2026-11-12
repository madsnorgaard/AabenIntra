<?php

declare(strict_types=1);

namespace Drupal\aabenintra_ops\Check;

/**
 * A single operational check.
 *
 * Implementations are registered as services tagged `aabenintra_ops.check`
 * and collected by the CheckRunner. A check must be side-effect free: it
 * reads state and renders pages but never writes config, content or files.
 */
interface HealthCheckInterface {

  /**
   * Post-install / post-deploy tenant validation (drush aabenintra:validate).
   */
  public const GROUP_VALIDATE = 'validate';

  /**
   * Tenant isolation contract (drush aabenintra:verify-isolation).
   */
  public const GROUP_ISOLATION = 'isolation';

  /**
   * Stable machine name, used as the row key in reports.
   */
  public function id(): string;

  /**
   * Short human-readable label.
   */
  public function label(): string;

  /**
   * The command groups this check belongs to.
   *
   * @return list<string>
   *   One or more of the GROUP_* constants.
   */
  public function groups(): array;

  /**
   * Runs the check. Exceptions are caught by the runner and reported as fail.
   */
  public function run(): CheckResult;

}
