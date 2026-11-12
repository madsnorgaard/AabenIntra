<?php

declare(strict_types=1);

namespace Drupal\aabenintra_audit\Controller;

use Drupal\aabenintra_audit\AuditRepository;
use Drupal\aabenintra_audit\Form\AuditFilterForm;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Query\PagerSelectExtender;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Authentication audit log: paged, filterable table + streamed CSV export.
 *
 * Mirrors the aabenintra_compliance ReportController shape (plain render
 * arrays, no Views) so the two admin reports look and behave alike.
 */
final class AuditLogController extends ControllerBase {

  private const PAGE_SIZE = 50;
  private const CSV_CHUNK = 500;

  public function __construct(
    private readonly AuditRepository $repository,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aabenintra_audit.repository'),
      $container->get('date.formatter'),
    );
  }

  /**
   * The /admin/reports/auth-audit page.
   *
   * @return array<string,mixed>
   */
  public function report(Request $request): array {
    $filters = AuditFilterForm::filtersFromQuery($request->query->all());
    /** @var \Drupal\Core\Database\Query\PagerSelectExtender $query */
    $query = $this->repository->select($filters)->extend(PagerSelectExtender::class);
    $query->limit(self::PAGE_SIZE);
    $rows = array_map([AuditRepository::class, 'decode'], $query->execute()->fetchAll(FetchAs::Associative));

    $header = [
      $this->t('Time'),
      $this->t('Event'),
      $this->t('User'),
      $this->t('Attempted name'),
      $this->t('IP'),
      $this->t('User agent'),
      $this->t('Details'),
    ];
    $table_rows = [];
    $user_storage = $this->entityTypeManager()->getStorage('user');
    foreach ($rows as $row) {
      $user_cell = '';
      if ($row['uid'] > 0) {
        $account = $user_storage->load($row['uid']);
        $user_cell = $account
          ? Link::fromTextAndUrl($account->label(), Url::fromRoute('entity.user.canonical', ['user' => $row['uid']]))->toString()
          : $this->t('uid @uid (deleted)', ['@uid' => $row['uid']]);
      }
      $table_rows[] = [
        'data' => [
          $this->dateFormatter->format($row['created'], 'short'),
          ['data' => ['#markup' => '<code>' . htmlspecialchars((string) $row['event']) . '</code>']],
          $user_cell,
          (string) $row['username_attempted'],
          (string) $row['ip'] . ($row['ip_anonymised'] ? ' *' : ''),
          [
            'data' => Unicode::truncate((string) $row['user_agent'], 60, TRUE, TRUE),
            'title' => (string) $row['user_agent'],
          ],
          self::summariseDetails($row['details']),
        ],
        'class' => ['aabenintra-audit-severity-' . $row['severity']],
      ];
    }

    return [
      'filters' => $this->formBuilder()->getForm(AuditFilterForm::class),
      'export' => [
        '#type' => 'link',
        '#title' => $this->t('Download CSV (current filter)'),
        '#url' => Url::fromRoute('aabenintra_audit.report_csv', [], ['query' => array_filter($request->query->all(), 'is_string')]),
        '#attributes' => ['class' => ['button']],
      ],
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $table_rows,
        '#empty' => $this->t('No audit events match.'),
        '#attributes' => ['class' => ['aabenintra-audit-log']],
      ],
      'legend' => [
        '#markup' => '<p class="description">' . $this->t('* IP address anonymised for data minimisation. Session identifiers are stored only as hashes.') . '</p>',
      ],
      'pager' => ['#type' => 'pager'],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Streams the filtered rows as CSV without buffering the whole table.
   */
  public function csv(Request $request): StreamedResponse {
    $filters = AuditFilterForm::filtersFromQuery($request->query->all());
    $repository = $this->repository;
    $formatter = $this->dateFormatter;
    $response = new StreamedResponse(static function () use ($repository, $filters, $formatter): void {
      $out = fopen('php://output', 'w');
      if ($out === FALSE) {
        return;
      }
      fputcsv($out, ['id', 'time_utc', 'created', 'event', 'severity', 'uid', 'username_attempted', 'ip', 'ip_anonymised', 'user_agent', 'session_hash', 'details']);
      $offset = 0;
      do {
        $query = $repository->select($filters)->range($offset, self::CSV_CHUNK);
        $rows = $query->execute()->fetchAll(FetchAs::Associative);
        foreach ($rows as $row) {
          $row = AuditRepository::decode($row);
          fputcsv($out, [
            $row['id'],
            $formatter->format($row['created'], 'custom', 'Y-m-d\TH:i:s\Z', 'UTC'),
            $row['created'],
            $row['event'],
            $row['severity'],
            $row['uid'],
            $row['username_attempted'],
            $row['ip'],
            $row['ip_anonymised'],
            $row['user_agent'],
            $row['session_hash'],
            json_encode($row['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
          ]);
        }
        $offset += self::CSV_CHUNK;
        flush();
      } while (count($rows) === self::CSV_CHUNK);
      fclose($out);
    });
    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="auth-audit-' . date('Ymd-His') . '.csv"');
    $response->headers->set('Cache-Control', 'private, no-store');
    return $response;
  }

  /**
   * Renders details as "key: value, key: value" for the table.
   *
   * @param array<string,mixed> $details
   */
  private static function summariseDetails(array $details): string {
    $parts = [];
    foreach ($details as $key => $value) {
      if (is_array($value)) {
        $value = implode('|', array_map('strval', array_filter($value, 'is_scalar')));
      }
      elseif (is_bool($value)) {
        $value = $value ? 'yes' : 'no';
      }
      $parts[] = $key . ': ' . (string) $value;
    }
    return implode(', ', $parts);
  }

}
