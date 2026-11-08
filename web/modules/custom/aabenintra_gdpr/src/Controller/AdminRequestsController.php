<?php

declare(strict_types=1);

namespace Drupal\aabenintra_gdpr\Controller;

use Drupal\aabenintra_gdpr\ExportRequestStorage;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin overview of export requests and the erasure log (no Views needed).
 */
final class AdminRequestsController extends ControllerBase {

  public function __construct(
    private readonly ExportRequestStorage $requests,
    private readonly Connection $database,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aabenintra_gdpr.requests'),
      $container->get('database'),
      $container->get('date.formatter'),
    );
  }

  /**
   * @return array<string,mixed>
   */
  public function page(): array {
    $build = [];
    $build['intro'] = [
      '#markup' => '<p>' . $this->t('Export requests queued by users and completed erasures. Command-line equivalents: <code>drush aabenintra:gdpr-export</code>, <code>drush aabenintra:gdpr-erase</code>, <code>drush aabenintra:gdpr-inventory</code>. <a href=":settings">Settings</a>.', [
        ':settings' => Url::fromRoute('aabenintra_gdpr.settings')->toString(),
      ]) . '</p>',
    ];

    $rows = [];
    $user_storage = $this->entityTypeManager()->getStorage('user');
    foreach ($this->requests->all() as $row) {
      $account = $user_storage->load($row['uid']);
      $rows[] = [
        (int) $row['id'],
        $account ? $account->toLink()->toString() : $this->t('uid @uid (deleted)', ['@uid' => $row['uid']]),
        $row['status'],
        $this->date($row['created']),
        $this->date($row['completed']),
        $this->date($row['downloaded']),
        $this->date($row['expires']),
        $row['message'],
      ];
    }
    $build['requests'] = [
      '#type' => 'details',
      '#title' => $this->t('Export requests (@count)', ['@count' => count($rows)]),
      '#open' => TRUE,
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('ID'), $this->t('User'), $this->t('Status'), $this->t('Requested'), $this->t('Completed'), $this->t('Downloaded'), $this->t('Expires'), $this->t('Message')],
        '#rows' => $rows,
        '#empty' => $this->t('No export requests yet.'),
      ],
    ];

    $log_rows = [];
    $result = $this->database->select('aabenintra_gdpr_erasure', 'e')
      ->fields('e', ['id', 'subject_hash', 'mode', 'counts', 'performed_by', 'created'])
      ->orderBy('created', 'DESC')
      ->range(0, 200)
      ->execute();
    foreach ($result->fetchAll(FetchAs::Associative) as $row) {
      $counts = json_decode((string) $row['counts'], TRUE);
      $summary = [];
      if (is_array($counts)) {
        foreach ($counts as $collector => $count) {
          if ((int) $count > 0) {
            $summary[] = $collector . ': ' . (int) $count;
          }
        }
      }
      $performer = $user_storage->load($row['performed_by']);
      $log_rows[] = [
        (int) $row['id'],
        substr((string) $row['subject_hash'], 0, 16) . '...',
        $row['mode'],
        implode(', ', $summary) ?: '-',
        $performer ? $performer->getDisplayName() : $this->t('cli / uid @uid', ['@uid' => $row['performed_by']]),
        $this->date($row['created']),
      ];
    }
    $build['erasures'] = [
      '#type' => 'details',
      '#title' => $this->t('Erasure log (@count)', ['@count' => count($log_rows)]),
      '#open' => TRUE,
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('ID'), $this->t('Subject (hashed)'), $this->t('Mode'), $this->t('Rows affected'), $this->t('Performed by'), $this->t('When')],
        '#rows' => $log_rows,
        '#empty' => $this->t('No erasures recorded.'),
      ],
    ];
    $build['#cache']['max-age'] = 0;
    return $build;
  }

  private function date(mixed $timestamp): string {
    $timestamp = (int) $timestamp;
    return $timestamp > 0 ? $this->dateFormatter->format($timestamp, 'short') : '-';
  }

}
