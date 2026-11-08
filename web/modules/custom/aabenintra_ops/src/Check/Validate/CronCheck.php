<?php

declare(strict_types=1);

namespace Drupal\aabenintra_ops\Check\Validate;

use Drupal\aabenintra_ops\Check\CheckResult;
use Drupal\aabenintra_ops\Check\HealthCheckInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;

/**
 * Cron has run within the configured window (default 24h).
 *
 * Warn rather than fail: a fresh install has never run cron, and a tenant
 * without cron still serves pages; it just stops indexing and cleaning up.
 */
final class CronCheck implements HealthCheckInterface {

  public function __construct(
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'cron';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Cron ran recently';
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
    $maxAge = (int) ($this->configFactory->get('aabenintra_ops.settings')->get('cron_max_age') ?? 86400);
    $last = (int) $this->state->get('system.cron_last', 0);
    if ($last === 0) {
      return CheckResult::warn('Cron has never run on this tenant.');
    }
    $age = $this->time->getRequestTime() - $last;
    $details = ['last_run' => gmdate('c', $last), 'age_seconds' => $age];
    if ($age > $maxAge) {
      return CheckResult::warn(sprintf('Cron last ran %d hours ago.', intdiv($age, 3600)), $details);
    }
    return CheckResult::ok('Cron ran within the last ' . intdiv($maxAge, 3600) . ' hours.', $details);
  }

}
