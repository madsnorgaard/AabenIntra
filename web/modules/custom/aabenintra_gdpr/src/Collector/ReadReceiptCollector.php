<?php

declare(strict_types=1);

namespace Drupal\aabenintra_gdpr\Collector;

use Drupal\aabenintra_gdpr\ErasureMode;
use Drupal\user\UserInterface;

/**
 * Acknowledgements of mandatory reading (compliance receipts).
 *
 * Receipts prove that an employee acknowledged a mandatory instruction and
 * are kept as evidence for the employer (Art. 17(3)(b) and (e)). In anonymise
 * mode they therefore survive, attached to the pseudonymised account; nothing
 * in the row identifies the person once name and mail are replaced. In delete
 * mode the account row is gone, so the receipts are deleted too - the count
 * is preserved in the hashed erasure log.
 */
final class ReadReceiptCollector extends TableCollectorBase {

  public function id(): string {
    return 'read_receipts';
  }

  public function label(): string {
    return 'Mandatory-reading receipts';
  }

  public function module(): string {
    return 'aabenintra_compliance';
  }

  protected function table(): string {
    return 'aabenintra_read_receipt';
  }

  protected function uidColumns(): array {
    return ['uid'];
  }

  protected function exportColumns(): array {
    return ['nid', 'langcode', 'created'];
  }

  public function erase(UserInterface $subject, ErasureMode $mode, bool $dryRun): int {
    if ($mode === ErasureMode::Anonymise) {
      return $this->count($subject);
    }
    return parent::erase($subject, $mode, $dryRun);
  }

  public function erasureNote(ErasureMode $mode): string {
    return $mode === ErasureMode::Anonymise
      ? 'kept as audit evidence, attached to the pseudonymised account (Art. 17(3)(b))'
      : 'rows deleted; count retained in the hashed erasure log';
  }

}
