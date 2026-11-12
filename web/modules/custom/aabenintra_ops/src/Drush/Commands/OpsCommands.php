<?php

declare(strict_types=1);

namespace Drupal\aabenintra_ops\Drush\Commands;

use Consolidation\AnnotatedCommand\CommandResult;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\aabenintra_ops\Check\CheckReport;
use Drupal\aabenintra_ops\Check\CheckRunner;
use Drupal\aabenintra_ops\Check\HealthCheckInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tenant validation and isolation verification.
 */
final class OpsCommands extends DrushCommands {

  private const FIELD_LABELS = [
    'check' => 'Check',
    'label' => 'Label',
    'status' => 'Status',
    'message' => 'Message',
    'details' => 'Details',
  ];

  public function __construct(
    private readonly CheckRunner $runner,
  ) {
    parent::__construct();
  }

  /**
   * Instantiates the command class from the container.
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aabenintra_ops.check_runner'),
    );
  }

  /**
   * Validates a tenant after install or deploy.
   *
   * Exit code 1 when any check fails (or warns with --strict), 0 otherwise.
   *
   * @param array{strict: bool, format: string} $options
   *   Drush options.
   */
  #[CLI\Command(name: 'aabenintra:validate', aliases: ['ai-validate'])]
  #[CLI\Option(name: 'strict', description: 'Treat warnings as failures.')]
  #[CLI\Usage(name: 'drush aabenintra:validate', description: 'Table of all checks; exit 1 if any failed.')]
  #[CLI\Usage(name: 'drush aabenintra:validate --format=json --strict', description: 'Machine-readable output, warnings count as failures.')]
  #[CLI\FieldLabels(labels: self::FIELD_LABELS)]
  #[CLI\DefaultTableFields(fields: ['check', 'status', 'message', 'details'])]
  #[CLI\FilterDefaultField(field: 'status')]
  public function validate(array $options = ['strict' => FALSE, 'format' => 'table']): CommandResult {
    return $this->runGroup(HealthCheckInterface::GROUP_VALIDATE, (bool) $options['strict']);
  }

  /**
   * Verifies the tenant isolation contract of this container.
   *
   * Exit code 1 when any check fails (or warns with --strict), 0 otherwise.
   *
   * @param array{strict: bool, format: string} $options
   *   Drush options.
   */
  #[CLI\Command(name: 'aabenintra:verify-isolation', aliases: ['ai-isolation'])]
  #[CLI\Option(name: 'strict', description: 'Treat warnings as failures.')]
  #[CLI\Usage(name: 'drush aabenintra:verify-isolation', description: 'Table of all isolation checks; exit 1 if any failed.')]
  #[CLI\Usage(name: 'drush aabenintra:verify-isolation --format=json', description: 'Machine-readable output.')]
  #[CLI\FieldLabels(labels: self::FIELD_LABELS)]
  #[CLI\DefaultTableFields(fields: ['check', 'status', 'message', 'details'])]
  #[CLI\FilterDefaultField(field: 'status')]
  public function verifyIsolation(array $options = ['strict' => FALSE, 'format' => 'table']): CommandResult {
    return $this->runGroup(HealthCheckInterface::GROUP_ISOLATION, (bool) $options['strict']);
  }

  /**
   * Runs a check group and maps the report to a Drush result.
   */
  private function runGroup(string $group, bool $strict): CommandResult {
    $report = $this->runner->run($group);
    $this->logSummary($report, $strict);
    return CommandResult::dataWithExitCode(new RowsOfFields($report->toRows()), $report->exitCode($strict));
  }

  /**
   * Logs the one-line summary to stderr (keeps --format=json clean).
   */
  private function logSummary(CheckReport $report, bool $strict): void {
    $logger = $this->logger();
    if ($logger === NULL) {
      return;
    }
    $summary = $report->summary();
    if ($report->exitCode($strict) !== 0) {
      $logger->error(dt('@summary - FAILED', ['@summary' => $summary]));
      return;
    }
    if ($report->hasWarnings()) {
      $logger->warning(dt('@summary - passed with warnings', ['@summary' => $summary]));
      return;
    }
    $logger->success(dt('@summary - passed', ['@summary' => $summary]));
  }

}
