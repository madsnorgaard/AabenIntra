<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenintra_ops\Unit\Check;

use Drupal\aabenintra_ops\Check\CheckResult;
use Drupal\aabenintra_ops\Check\CheckRunner;
use Drupal\aabenintra_ops\Check\CheckStatus;
use Drupal\aabenintra_ops\Check\HealthCheckInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests CheckRunner.
 */
#[CoversClass(CheckRunner::class)]
#[Group('aabenintra_ops')]
final class CheckRunnerTest extends UnitTestCase {

  /**
   * Tests runs only checks of the group and catches exceptions.
   */
  public function testRunsOnlyChecksOfTheGroupAndCatchesExceptions(): void {
    $runner = new CheckRunner();
    $runner->addCheck($this->check('ok_check', [HealthCheckInterface::GROUP_VALIDATE], static fn() => CheckResult::ok('fine')));
    $runner->addCheck($this->check('both', [HealthCheckInterface::GROUP_VALIDATE, HealthCheckInterface::GROUP_ISOLATION], static fn() => CheckResult::warn('both')));
    $runner->addCheck($this->check('isolation_only', [HealthCheckInterface::GROUP_ISOLATION], static fn() => CheckResult::ok('iso')));
    $runner->addCheck($this->check('boom', [HealthCheckInterface::GROUP_VALIDATE], static function (): CheckResult {
      throw new \RuntimeException('database gone');
    }));

    $validate = $runner->run(HealthCheckInterface::GROUP_VALIDATE);
    self::assertSame(['ok_check', 'both', 'boom'], array_keys($validate->toRows()));
    self::assertSame(1, $validate->exitCode());
    $rows = $validate->toRows();
    self::assertSame('fail', $rows['boom']['status']);
    self::assertStringContainsString('RuntimeException', $rows['boom']['message']);
    self::assertStringContainsString('database gone', $rows['boom']['message']);

    $isolation = $runner->run(HealthCheckInterface::GROUP_ISOLATION);
    self::assertSame(['both', 'isolation_only'], array_keys($isolation->toRows()));
    self::assertSame(CheckStatus::Warn, $isolation->worstStatus());
    self::assertSame(0, $isolation->exitCode());
    self::assertSame(1, $isolation->exitCode(TRUE));
  }

  /**
   * Builds an anonymous check for the runner.
   *
   * @param string $id
   *   Check id.
   * @param list<string> $groups
   *   Groups the check belongs to.
   * @param \Closure $run
   *   Returns the CheckResult (or throws).
   */
  private function check(string $id, array $groups, \Closure $run): HealthCheckInterface {
    return new class($id, $groups, $run) implements HealthCheckInterface {

      /**
       * Constructs the stub.
       *
       * @param string $id
       *   Check id.
       * @param list<string> $groups
       *   Groups the check belongs to.
       * @param \Closure $run
       *   Returns the CheckResult (or throws).
       */
      public function __construct(
        private readonly string $id,
        private readonly array $groups,
        private readonly \Closure $run,
      ) {}

      /**
       * {@inheritdoc}
       */
      public function id(): string {
        return $this->id;
      }

      /**
       * {@inheritdoc}
       */
      public function label(): string {
        return ucfirst($this->id);
      }

      /**
       * {@inheritdoc}
       */
      public function groups(): array {
        return $this->groups;
      }

      /**
       * {@inheritdoc}
       */
      public function run(): CheckResult {
        return ($this->run)();
      }

    };
  }

}
