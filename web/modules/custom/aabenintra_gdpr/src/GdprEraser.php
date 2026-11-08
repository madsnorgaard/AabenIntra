<?php

declare(strict_types=1);

namespace Drupal\aabenintra_gdpr;

use Drupal\aabenintra_gdpr\Collector\CollectorManager;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Site\Settings;
use Drupal\user\UserDataInterface;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Anonymises or deletes a data subject (GDPR Art. 17).
 *
 * Order of operations: refusal checks, then every active collector erases its
 * rows while the uid is still valid, then the account is pseudonymised
 * (Anonymise) or deleted through core's cancel path (Delete). A hashed audit
 * row is written last. A dry run performs only the counting.
 */
final class GdprEraser {

  public const ROLE_ADMINISTRATOR = 'administrator';

  public function __construct(
    private readonly CollectorManager $collectors,
    private readonly Anonymizer $anonymizer,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly UserDataInterface $userData,
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Reasons an account must not be erased without --force (empty = none).
   *
   * @return list<string>
   */
  public function refusals(UserInterface $subject): array {
    $reasons = [];
    $uid = (int) $subject->id();
    if ($uid === 0) {
      $reasons[] = 'uid 0 is the anonymous user';
    }
    if ($uid === 1) {
      $reasons[] = 'uid 1 is the site owner account';
    }
    if ($subject->hasRole(self::ROLE_ADMINISTRATOR)) {
      $reasons[] = 'account holds the administrator role';
    }
    return $reasons;
  }

  /**
   * Runs the erasure.
   *
   * @throws \RuntimeException
   *   When refused and not forced, or for uid 0 which can never be erased.
   */
  public function erase(UserInterface $subject, ErasureMode $mode, bool $dryRun = FALSE, bool $force = FALSE): ErasureReport {
    $uid = (int) $subject->id();
    if ($uid === 0) {
      throw new \RuntimeException('The anonymous user (uid 0) cannot be erased.');
    }
    $refusals = $this->refusals($subject);
    if ($refusals !== [] && !$force) {
      throw new \RuntimeException('Refusing to erase uid ' . $uid . ': ' . implode('; ', $refusals) . '. Pass --force to override.');
    }

    $sections = [];
    foreach ($this->collectors->active() as $id => $collector) {
      $count = $dryRun ? $collector->count($subject) : $collector->erase($subject, $mode, FALSE);
      $sections[$id] = [
        'module' => $collector->module(),
        'label' => $collector->label(),
        'count' => $count,
        'note' => $collector->erasureNote($mode),
      ];
    }

    $accountAction = match ($mode) {
      ErasureMode::Anonymise => 'account kept, blocked and pseudonymised as ' . $this->anonymizer->name($uid),
      ErasureMode::Delete => 'account deleted via user_cancel_delete',
    };

    if (!$dryRun) {
      // Preferences and any other per-user key/value data, for every module.
      $this->userData->delete(NULL, $uid);
      match ($mode) {
        ErasureMode::Anonymise => $this->pseudonymiseAccount($subject),
        ErasureMode::Delete => $this->deleteAccount($subject),
      };
    }

    $report = new ErasureReport($uid, $mode, $dryRun, $sections, $accountAction);
    if (!$dryRun) {
      $this->writeAuditRow($report);
      $this->logger->notice('Erasure (@mode) completed for subject @hash: @total rows across @n collectors.', [
        '@mode' => $mode->value,
        '@hash' => substr($this->subjectHash($uid), 0, 12),
        '@total' => $report->total(),
        '@n' => count($sections),
      ]);
    }
    return $report;
  }

  /**
   * The uid authored content is reassigned to in anonymise mode.
   */
  public function reassignUid(): int {
    $uid = (int) $this->configFactory->get('aabenintra_gdpr.settings')->get('reassign_uid');
    if ($uid > 0 && $this->entityTypeManager->getStorage('user')->load($uid) === NULL) {
      $this->logger->warning('Configured reassign_uid @uid does not exist; falling back to the anonymous user.', ['@uid' => $uid]);
      return 0;
    }
    return max(0, $uid);
  }

  /**
   * Hashed subject reference used in the erasure log.
   */
  public function subjectHash(int $uid): string {
    return $this->anonymizer->subjectHash($uid, Settings::getHashSalt());
  }

  /**
   * Blocks the account and replaces every identifying value.
   */
  private function pseudonymiseAccount(UserInterface $subject): void {
    $uid = (int) $subject->id();
    $subject->setUsername($this->anonymizer->name($uid));
    $subject->setEmail($this->anonymizer->mail($uid));
    $subject->set('init', $this->anonymizer->mail($uid));
    $subject->setPassword($this->anonymizer->randomPassword());
    $subject->block();
    foreach ($subject->getRoles(TRUE) as $rid) {
      $subject->removeRole($rid);
    }
    if ($subject->hasField('timezone')) {
      $subject->set('timezone', NULL);
    }
    // Configurable fields (profile data) are emptied by the profile collector
    // before this point; clear them here as well so a site without that
    // collector still ends up with a bare row.
    foreach ($subject->getFieldDefinitions() as $name => $definition) {
      if (!$definition->isComputed() && $definition->getFieldStorageDefinition()->isBaseField() === FALSE) {
        $subject->set($name, NULL);
      }
    }
    $subject->save();
  }

  /**
   * Deletes the account the way core's cancel path does (user_cancel_delete).
   *
   * user_cancel() only schedules _user_cancel() as a batch operation and
   * expects the caller to run the batch; there is no batch runner on cron or
   * in a kernel test, so the operation is invoked directly. The entity delete
   * fires hook_user_predelete(), which is where node and comment cascade.
   */
  private function deleteAccount(UserInterface $subject): void {
    if (function_exists('_user_cancel')) {
      _user_cancel([], $subject, 'user_cancel_delete');
      return;
    }
    $subject->delete();
    if ((int) $subject->id() === (int) $this->currentUser->id()) {
      $this->logger->warning('Erased the current user; session is left to expire.');
    }
  }

  private function writeAuditRow(ErasureReport $report): void {
    $this->database->insert('aabenintra_gdpr_erasure')
      ->fields([
        'subject_hash' => $this->subjectHash($report->uid),
        'mode' => $report->mode->value,
        'counts' => json_encode($report->counts(), JSON_THROW_ON_ERROR),
        'performed_by' => (int) $this->currentUser->id(),
        'created' => $this->time->getRequestTime(),
      ])
      ->execute();
  }

}
