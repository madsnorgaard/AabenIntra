<?php

declare(strict_types=1);

namespace Drupal\aabenintra_ops\Check;

/**
 * Collects tagged checks and runs the ones belonging to a group.
 */
final class CheckRunner {

  /**
   * All collected checks, in service registration order.
   *
   * @var list<HealthCheckInterface>
   */
  private array $checks = [];

  /**
   * Service collector callback for the `aabenintra_ops.check` tag.
   */
  public function addCheck(HealthCheckInterface $check): void {
    $this->checks[] = $check;
  }

  /**
   * The checks that belong to a group.
   *
   * @return list<HealthCheckInterface>
   *   Checks whose groups() contains $group.
   */
  public function checksFor(string $group): array {
    return array_values(array_filter(
      $this->checks,
      static fn(HealthCheckInterface $check): bool => in_array($group, $check->groups(), TRUE),
    ));
  }

  /**
   * Runs every check in the group; a throwing check is reported as fail.
   */
  public function run(string $group): CheckReport {
    $report = new CheckReport();
    foreach ($this->checksFor($group) as $check) {
      try {
        $result = $check->run();
      }
      catch (\Throwable $e) {
        $result = CheckResult::fail('Check threw ' . get_class($e) . ': ' . $e->getMessage());
      }
      $report->add($check->id(), $check->label(), $result);
    }
    return $report;
  }

}
