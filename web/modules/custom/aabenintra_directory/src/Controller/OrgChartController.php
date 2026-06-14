<?php

declare(strict_types=1);

namespace Drupal\aabenintra_directory\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Organisation chart built from the organisation taxonomy.
 */
final class OrgChartController extends ControllerBase {

  public function __construct(
    private readonly EntityRepositoryInterface $entityRepository,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('entity.repository'));
  }

  public function page(): array {
    return [
      '#theme' => 'aabenintra_org_chart',
      '#tree' => $this->level(0),
      '#attached' => ['library' => ['aabenintra_directory/directory']],
      '#cache' => [
        'tags' => ['taxonomy_term_list:organisation', 'user_list'],
        'contexts' => ['languages:language_interface'],
      ],
    ];
  }

  /**
   * Recursively builds the org tree under a parent term id.
   *
   * @return array<int,array<string,mixed>>
   */
  private function level(int $parent): array {
    $term_storage = $this->entityTypeManager()->getStorage('taxonomy_term');
    $out = [];
    foreach ($term_storage->loadTree('organisation', $parent, 1, TRUE) as $term) {
      $translated = $this->entityRepository->getTranslationFromContext($term);
      $level = $term->hasField('field_org_level') && !$term->get('field_org_level')->isEmpty()
        ? $term->get('field_org_level')->value : '';
      $out[] = [
        'name' => $translated->label(),
        'level' => $level,
        'count' => $this->memberCount((int) $term->id()),
        'url' => Url::fromRoute('aabenintra_directory.directory', [], ['query' => ['unit' => $term->id()]])->toString(),
        'children' => $this->level((int) $term->id()),
      ];
    }
    return $out;
  }

  private function memberCount(int $tid): int {
    return (int) $this->entityTypeManager()->getStorage('user')->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('field_primary_org_unit', $tid)
      ->count()
      ->execute();
  }

}
