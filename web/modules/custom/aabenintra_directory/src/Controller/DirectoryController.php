<?php

declare(strict_types=1);

namespace Drupal\aabenintra_directory\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Searchable employee directory.
 */
final class DirectoryController extends ControllerBase {

  public function __construct(
    private readonly RequestStack $requestStack,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('request_stack'));
  }

  public function page(): array {
    $request = $this->requestStack->getCurrentRequest();
    $q = trim((string) $request->query->get('q', ''));
    $unit = (int) $request->query->get('unit', 0);
    $location = (int) $request->query->get('location', 0);
    $skill = (int) $request->query->get('skill', 0);

    $user_storage = $this->entityTypeManager()->getStorage('user');
    $query = $user_storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', 1)
      ->condition('uid', 0, '>')
      ->sort('name');
    if ($q !== '') {
      $query->condition($query->orConditionGroup()
        ->condition('name', $q, 'CONTAINS')
        ->condition('field_job_title', $q, 'CONTAINS'));
    }
    if ($unit) {
      $query->condition('field_primary_org_unit', $unit);
    }
    if ($location) {
      $query->condition('field_location', $location);
    }
    if ($skill) {
      $query->condition('field_skills', $skill);
    }
    $uids = $query->range(0, 200)->execute();

    $image_style = $this->entityTypeManager()->getStorage('image_style')->load('thumbnail');
    $people = [];
    foreach ($user_storage->loadMultiple($uids) as $user) {
      $photo = NULL;
      if ($user->hasField('field_photo') && !$user->get('field_photo')->isEmpty()) {
        $file = $user->get('field_photo')->entity;
        if ($file && $image_style) {
          $photo = $image_style->buildUrl($file->getFileUri());
        }
      }
      $people[] = [
        'name' => $user->getDisplayName(),
        'title' => $this->fieldValue($user, 'field_job_title'),
        'unit' => $this->refLabel($user, 'field_primary_org_unit'),
        'location' => $this->refLabel($user, 'field_location'),
        'phone' => $this->fieldValue($user, 'field_phone'),
        'email' => $user->getEmail(),
        'photo' => $photo,
        'initial' => mb_substr($user->getDisplayName(), 0, 1),
        'skills' => $this->refLabels($user, 'field_skills'),
        'url' => $user->toUrl()->toString(),
      ];
    }

    return [
      '#theme' => 'aabenintra_directory',
      '#people' => $people,
      '#total' => count($people),
      '#query' => ['q' => $q, 'unit' => $unit, 'location' => $location, 'skill' => $skill],
      '#filters' => [
        'units' => $this->termOptions('organisation', TRUE),
        'locations' => $this->termOptions('location'),
        'skills' => $this->termOptions('skills'),
      ],
      '#attached' => ['library' => ['aabenintra_directory/directory']],
      '#cache' => [
        'tags' => ['user_list', 'taxonomy_term_list:organisation'],
        'contexts' => ['url.query_args', 'languages:language_interface'],
      ],
    ];
  }

  private function fieldValue($entity, string $field): string {
    return ($entity->hasField($field) && !$entity->get($field)->isEmpty()) ? (string) $entity->get($field)->value : '';
  }

  private function refLabel($entity, string $field): string {
    if ($entity->hasField($field) && !$entity->get($field)->isEmpty()) {
      $term = $entity->get($field)->entity;
      return $term ? $term->label() : '';
    }
    return '';
  }

  /**
   * @return string[]
   */
  private function refLabels($entity, string $field): array {
    $out = [];
    if ($entity->hasField($field)) {
      foreach ($entity->get($field)->referencedEntities() as $term) {
        $out[] = $term->label();
      }
    }
    return $out;
  }

  /**
   * @return array<int,string>
   */
  private function termOptions(string $vid, bool $indent = FALSE): array {
    $options = [];
    foreach ($this->entityTypeManager()->getStorage('taxonomy_term')->loadTree($vid, 0, NULL, FALSE) as $term) {
      $prefix = $indent ? str_repeat('— ', (int) $term->depth) : '';
      $options[(int) $term->tid] = $prefix . $term->name;
    }
    return $options;
  }

}
