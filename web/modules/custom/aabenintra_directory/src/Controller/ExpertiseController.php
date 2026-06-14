<?php

declare(strict_types=1);

namespace Drupal\aabenintra_directory\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * "Who knows...?" expertise finder.
 *
 * Skill-centric inverse of the directory: browse skills by how many colleagues
 * hold them, then drill into a skill to see (and contact) the experts. Reduces
 * internal support load by routing questions to the right people.
 */
final class ExpertiseController extends ControllerBase {

  public function __construct(
    private readonly RequestStack $requestStack,
    private readonly EntityRepositoryInterface $entityRepository,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('request_stack'),
      $container->get('entity.repository'),
    );
  }

  public function page(): array {
    $request = $this->requestStack->getCurrentRequest();
    $q = trim((string) $request->query->get('q', ''));
    $skill = (int) $request->query->get('skill', 0);

    $term_storage = $this->entityTypeManager()->getStorage('taxonomy_term');
    $user_storage = $this->entityTypeManager()->getStorage('user');

    // Build the skill index: every skill with its holder count.
    $skills = [];
    foreach ($term_storage->loadTree('skills', 0, NULL, TRUE) as $term) {
      $term = $this->entityRepository->getTranslationFromContext($term);
      $label = $term->label();
      if ($q !== '' && mb_stripos($label, $q) === FALSE) {
        continue;
      }
      $count = $this->holderCount((int) $term->id());
      if ($count === 0 && $q === '') {
        continue;
      }
      $skills[] = [
        'tid' => (int) $term->id(),
        'name' => $label,
        'count' => $count,
        'url' => Url::fromRoute('aabenintra_directory.expertise', [], ['query' => ['skill' => $term->id()]])->toString(),
        'selected' => $skill === (int) $term->id(),
      ];
    }
    // Most-held skills first, then alphabetical.
    usort($skills, static fn(array $a, array $b): int => $b['count'] <=> $a['count'] ?: strnatcasecmp($a['name'], $b['name']));

    // If a skill is selected, list its experts with contact details.
    $experts = [];
    $selected_name = '';
    if ($skill) {
      $selected_term = $term_storage->load($skill);
      if ($selected_term) {
        $selected_name = $this->entityRepository->getTranslationFromContext($selected_term)->label();
      }
      $image_style = $this->entityTypeManager()->getStorage('image_style')->load('thumbnail');
      $uids = $user_storage->getQuery()
        ->accessCheck(TRUE)
        ->condition('status', 1)
        ->condition('uid', 0, '>')
        ->condition('field_skills', $skill)
        ->sort('name')
        ->range(0, 200)
        ->execute();
      foreach ($user_storage->loadMultiple($uids) as $user) {
        $photo = NULL;
        if ($user->hasField('field_photo') && !$user->get('field_photo')->isEmpty()) {
          $file = $user->get('field_photo')->entity;
          if ($file && $image_style) {
            $photo = $image_style->buildUrl($file->getFileUri());
          }
        }
        $experts[] = [
          'name' => $user->getDisplayName(),
          'title' => $this->fieldValue($user, 'field_job_title'),
          'unit' => $this->refLabel($user, 'field_primary_org_unit'),
          'location' => $this->refLabel($user, 'field_location'),
          'phone' => $this->fieldValue($user, 'field_phone'),
          'email' => $user->getEmail(),
          'photo' => $photo,
          'initial' => mb_substr($user->getDisplayName(), 0, 1),
          'url' => $user->toUrl()->toString(),
        ];
      }
    }

    return [
      '#theme' => 'aabenintra_expertise',
      '#skills' => $skills,
      '#experts' => $experts,
      '#selected_skill' => $skill,
      '#selected_name' => $selected_name,
      '#q' => $q,
      '#attached' => ['library' => ['aabenintra_directory/directory']],
      '#cache' => [
        'tags' => ['user_list', 'taxonomy_term_list:skills'],
        'contexts' => ['url.query_args', 'languages:language_interface'],
      ],
    ];
  }

  /**
   * Counts active users who hold a given skill.
   */
  private function holderCount(int $tid): int {
    return (int) $this->entityTypeManager()->getStorage('user')->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', 1)
      ->condition('uid', 0, '>')
      ->condition('field_skills', $tid)
      ->count()
      ->execute();
  }

  private function fieldValue($entity, string $field): string {
    return ($entity->hasField($field) && !$entity->get($field)->isEmpty()) ? (string) $entity->get($field)->value : '';
  }

  private function refLabel($entity, string $field): string {
    if ($entity->hasField($field) && !$entity->get($field)->isEmpty()) {
      $term = $entity->get($field)->entity;
      return $term ? $this->entityRepository->getTranslationFromContext($term)->label() : '';
    }
    return '';
  }

}
