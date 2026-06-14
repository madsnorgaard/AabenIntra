<?php

declare(strict_types=1);

namespace Drupal\aabenintra_compliance\Controller;

use Drupal\aabenintra_compliance\ReceiptService;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mandatory-reading compliance report.
 */
final class ReportController extends ControllerBase {

  public function __construct(
    private readonly ReceiptService $receipts,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('aabenintra_compliance.receipts'));
  }

  /**
   * Returns mandatory nodes with their acknowledgement counts.
   *
   * @return array<int,array{node:\Drupal\node\NodeInterface,read:int,total:int}>
   */
  private function rows(): array {
    $node_storage = $this->entityTypeManager()->getStorage('node');
    $ids = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_mandatory', 1)
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->execute();
    // Active authenticated users = the approximate target population.
    $total = (int) $this->entityTypeManager()->getStorage('user')->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('uid', 0, '>')
      ->count()
      ->execute();
    $rows = [];
    foreach ($node_storage->loadMultiple($ids) as $node) {
      $rows[] = ['node' => $node, 'read' => $this->receipts->countForNode((int) $node->id()), 'total' => $total];
    }
    return $rows;
  }

  public function report(): array {
    $header = [$this->t('Title'), $this->t('Type'), $this->t('Acknowledged'), $this->t('Of users'), $this->t('%')];
    $table_rows = [];
    foreach ($this->rows() as $r) {
      $pct = $r['total'] > 0 ? round(100 * $r['read'] / $r['total']) : 0;
      $table_rows[] = [
        $r['node']->toLink(),
        $r['node']->type->entity ? $r['node']->type->entity->label() : $r['node']->bundle(),
        $r['read'],
        $r['total'],
        $pct . '%',
      ];
    }
    return [
      'export' => [
        '#type' => 'link',
        '#title' => $this->t('Download CSV'),
        '#url' => Url::fromRoute('aabenintra_compliance.report_csv'),
        '#attributes' => ['class' => ['button']],
      ],
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $table_rows,
        '#empty' => $this->t('No mandatory-reading content yet.'),
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  public function csv(): Response {
    $out = fopen('php://temp', 'r+');
    fputcsv($out, ['Title', 'Type', 'Acknowledged', 'Of users', 'Percent']);
    foreach ($this->rows() as $r) {
      $pct = $r['total'] > 0 ? round(100 * $r['read'] / $r['total']) : 0;
      fputcsv($out, [$r['node']->label(), $r['node']->bundle(), $r['read'], $r['total'], $pct]);
    }
    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);
    return new Response($csv, 200, [
      'Content-Type' => 'text/csv; charset=utf-8',
      'Content-Disposition' => 'attachment; filename="mandatory-reads.csv"',
    ]);
  }

}
