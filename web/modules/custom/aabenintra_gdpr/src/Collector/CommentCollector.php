<?php

declare(strict_types=1);

namespace Drupal\aabenintra_gdpr\Collector;

use Drupal\aabenintra_gdpr\ErasureMode;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\user\UserInterface;

/**
 * Comments the subject wrote.
 */
final class CommentCollector extends CollectorBase {

  use ReassignTargetTrait;

  public function __construct(
    ModuleHandlerInterface $moduleHandler,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct($moduleHandler);
  }

  public function id(): string {
    return 'comments';
  }

  public function label(): string {
    return 'Comments';
  }

  public function module(): string {
    return 'comment';
  }

  public function count(UserInterface $subject): int {
    return (int) $this->entityTypeManager->getStorage('comment')->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $subject->id())
      ->count()
      ->execute();
  }

  public function collect(UserInterface $subject): array {
    $items = [];
    foreach ($this->load($subject) as $comment) {
      $items[] = [
        'cid' => (int) $comment->id(),
        'on' => $comment->getCommentedEntityTypeId() . '/' . $comment->getCommentedEntityId(),
        'subject' => $comment->getSubject(),
        'body' => $comment->hasField('comment_body') ? (string) $comment->get('comment_body')->value : '',
        'status' => $comment->isPublished(),
        'created' => $this->iso($comment->getCreatedTime()),
      ];
    }
    return $items;
  }

  /**
   * Anonymise: comments are reassigned and the stored author name replaced
   * with the site's anonymous label (core user_cancel_reassign behaviour).
   * Delete: core's comment hook_user_predelete() removes them.
   */
  public function erase(UserInterface $subject, ErasureMode $mode, bool $dryRun): int {
    $count = $this->count($subject);
    if ($dryRun || $mode === ErasureMode::Delete || $count === 0) {
      return $count;
    }
    $target = $this->reassignTarget($this->configFactory, $this->entityTypeManager);
    $anonymous = (string) $this->configFactory->get('user.settings')->get('anonymous');
    foreach ($this->load($subject) as $comment) {
      $comment->setOriginal(clone $comment);
      foreach (array_keys($comment->getTranslationLanguages()) as $langcode) {
        $translation = $comment->getTranslation($langcode);
        $translation->setOwnerId($target);
        $translation->setAuthorName($target === 0 ? $anonymous : '');
        $translation->set('mail', NULL);
        $translation->set('homepage', NULL);
      }
      $comment->save();
    }
    return $count;
  }

  public function erasureNote(ErasureMode $mode): string {
    return $mode === ErasureMode::Anonymise
      ? 'kept; reassigned to reassign_uid, stored author name/mail cleared'
      : 'deleted by core (comment hook_user_predelete) together with the account';
  }

  /**
   * @return list<\Drupal\comment\CommentInterface>
   */
  private function load(UserInterface $subject): array {
    $storage = $this->entityTypeManager->getStorage('comment');
    $cids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $subject->id())
      ->sort('created', 'ASC')
      ->execute();
    return array_values($storage->loadMultiple($cids));
  }

}
