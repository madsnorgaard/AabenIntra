<?php

declare(strict_types=1);

namespace Drupal\aabenintra_audit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * Cron housekeeping: delete old rows, anonymise IPs on ageing rows.
 *
 * Both thresholds come from aabenintra_audit.settings and a value of 0
 * disables that step. Anonymisation keeps the row (the fact that a login
 * happened stays auditable) but truncates the IP so it no longer identifies
 * a device or household (GDPR data minimisation / storage limitation).
 */
final class Retention {

  public function __construct(
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Runs both steps; called from hook_cron().
   *
   * @return array{purged:int,anonymised:int}
   */
  public function run(): array {
    $anonymised = $this->anonymise();
    $purged = $this->purge();
    if ($purged > 0 || $anonymised > 0) {
      $this->logger->info('Audit retention: anonymised @a rows, purged @p rows.', ['@a' => $anonymised, '@p' => $purged]);
    }
    return ['purged' => $purged, 'anonymised' => $anonymised];
  }

  /**
   * Deletes rows older than retention_days. Returns the number removed.
   */
  public function purge(?int $now = NULL): int {
    $days = (int) $this->configFactory->get('aabenintra_audit.settings')->get('retention_days');
    if ($days <= 0) {
      return 0;
    }
    $cutoff = ($now ?? $this->time->getRequestTime()) - $days * 86400;
    return (int) $this->database->delete(AuditRecorder::TABLE)
      ->condition('created', $cutoff, '<')
      ->execute();
  }

  /**
   * Anonymises IPs on rows older than anonymise_ip_after_days.
   *
   * Works per distinct address so a busy site needs one UPDATE per address,
   * not per row. Returns the number of rows changed.
   */
  public function anonymise(?int $now = NULL): int {
    $days = (int) $this->configFactory->get('aabenintra_audit.settings')->get('anonymise_ip_after_days');
    if ($days <= 0) {
      return 0;
    }
    $cutoff = ($now ?? $this->time->getRequestTime()) - $days * 86400;
    $ips = $this->database->select(AuditRecorder::TABLE, 'a')
      ->fields('a', ['ip'])
      ->distinct()
      ->condition('a.created', $cutoff, '<')
      ->condition('a.ip_anonymised', 0)
      ->condition('a.ip', '', '<>')
      ->range(0, 500)
      ->execute()
      ->fetchCol();
    $changed = 0;
    foreach ($ips as $ip) {
      $ip = (string) $ip;
      $changed += (int) $this->database->update(AuditRecorder::TABLE)
        ->fields(['ip' => IpAnonymiser::anonymise($ip), 'ip_anonymised' => 1])
        ->condition('ip', $ip)
        ->condition('created', $cutoff, '<')
        ->condition('ip_anonymised', 0)
        ->execute();
    }
    return $changed;
  }

}
