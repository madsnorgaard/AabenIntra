<?php

declare(strict_types=1);

namespace Drupal\aabenintra_gdpr;

/**
 * Outcome of an erasure (or dry run): per-collector counts plus account facts.
 */
final class ErasureReport {

  /**
   * @param array<string,array{module:string,label:string,count:int,note:string}> $sections
   */
  public function __construct(
    public readonly int $uid,
    public readonly ErasureMode $mode,
    public readonly bool $dryRun,
    public readonly array $sections,
    public readonly string $accountAction,
  ) {}

  /**
   * Total rows affected across collectors.
   */
  public function total(): int {
    return array_sum(array_map(static fn(array $s): int => $s['count'], $this->sections));
  }

  /**
   * Compact counts map for the audit log (collector id => rows).
   *
   * @return array<string,int>
   */
  public function counts(): array {
    return array_map(static fn(array $s): int => $s['count'], $this->sections);
  }

}
