<?php

declare(strict_types=1);

namespace Drupal\aabenintra_gdpr;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\UserInterface;

/**
 * Turns a "<uid|mail>" command-line argument into a user entity.
 */
final class SubjectResolver {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Resolves a uid or e-mail address to a user, or NULL if there is none.
   */
  public function resolve(string $subject): ?UserInterface {
    $subject = trim($subject);
    $storage = $this->entityTypeManager->getStorage('user');
    if ($subject === '') {
      return NULL;
    }
    if (ctype_digit($subject)) {
      $user = $storage->load((int) $subject);
      return $user instanceof UserInterface ? $user : NULL;
    }
    if (str_contains($subject, '@')) {
      $users = $storage->loadByProperties(['mail' => $subject]);
      $user = reset($users);
      return $user instanceof UserInterface ? $user : NULL;
    }
    $users = $storage->loadByProperties(['name' => $subject]);
    $user = reset($users);
    return $user instanceof UserInterface ? $user : NULL;
  }

}
