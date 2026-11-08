<?php

declare(strict_types=1);

namespace Drupal\aabenintra_gdpr\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\aabenintra_gdpr\Collector\CollectorManager;
use Drupal\aabenintra_gdpr\ErasureMode;
use Drupal\aabenintra_gdpr\GdprEraser;
use Drupal\aabenintra_gdpr\GdprExporter;
use Drupal\aabenintra_gdpr\SubjectResolver;
use Drupal\user\UserInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for GDPR data-subject requests.
 *
 * All commands exit non-zero when the subject cannot be resolved or the
 * operation fails, so they are safe to chain in scripts.
 */
final class GdprCommands extends DrushCommands {

  public function __construct(
    private readonly SubjectResolver $resolver,
    private readonly GdprExporter $exporter,
    private readonly GdprEraser $eraser,
    private readonly CollectorManager $collectors,
  ) {
    parent::__construct();
  }

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aabenintra_gdpr.subject_resolver'),
      $container->get('aabenintra_gdpr.exporter'),
      $container->get('aabenintra_gdpr.eraser'),
      $container->get('aabenintra_gdpr.collector_manager'),
    );
  }

  /**
   * Exports all personal data held about a user as JSON (GDPR Art. 15/20).
   *
   * @param array<string,mixed> $options
   */
  #[CLI\Command(name: 'aabenintra:gdpr-export', aliases: ['gdpr-export'])]
  #[CLI\Argument(name: 'subject', description: 'User id, e-mail address or account name.')]
  #[CLI\Option(name: 'output', description: 'Destination file path. Default: private://gdpr-exports/<uid>-<timestamp>.json. Use "-" for stdout.')]
  #[CLI\Usage(name: 'drush aabenintra:gdpr-export 42', description: 'Write the export to the private file system.')]
  #[CLI\Usage(name: 'drush aabenintra:gdpr-export jane@example.org --output=/tmp/jane.json', description: 'Write to an explicit path.')]
  #[CLI\Usage(name: 'drush aabenintra:gdpr-export 42 --output=-', description: 'Print the JSON to stdout.')]
  public function export(string $subject, array $options = ['output' => NULL]): int {
    $user = $this->resolve($subject);
    if ($user === NULL) {
      return self::EXIT_FAILURE;
    }
    try {
      if ($options['output'] === '-') {
        $this->output()->write($this->exporter->encode($this->exporter->buildDocument($user)));
        return self::EXIT_SUCCESS;
      }
      $written = $this->exporter->export($user, $options['output'] ?? NULL);
    }
    catch (\Throwable $e) {
      $this->logger()->error(dt('Export failed: @message', ['@message' => $e->getMessage()]));
      return self::EXIT_FAILURE;
    }
    $this->logger()->success(dt('Export for uid @uid written to @path', ['@uid' => $user->id(), '@path' => $written]));
    return self::EXIT_SUCCESS;
  }

  /**
   * Anonymises or deletes a user and the data held about them (Art. 17).
   *
   * @param array<string,mixed> $options
   */
  #[CLI\Command(name: 'aabenintra:gdpr-erase', aliases: ['gdpr-erase'])]
  #[CLI\Argument(name: 'subject', description: 'User id, e-mail address or account name.')]
  #[CLI\Option(name: 'mode', description: 'anonymise (keep content, pseudonymise the account) or delete (cascade-delete account and content).')]
  #[CLI\Option(name: 'dry-run', description: 'Report what would be affected without changing anything.')]
  #[CLI\Option(name: 'force', description: 'Also allow uid 1 and accounts holding the administrator role.')]
  #[CLI\Usage(name: 'drush aabenintra:gdpr-erase 42 --mode=anonymise --dry-run', description: 'Show per-source counts for uid 42.')]
  #[CLI\Usage(name: 'drush aabenintra:gdpr-erase jane@example.org --mode=delete', description: 'Delete the account and everything it authored.')]
  public function erase(string $subject, array $options = ['mode' => 'anonymise', 'dry-run' => FALSE, 'force' => FALSE]): int {
    $user = $this->resolve($subject);
    if ($user === NULL) {
      return self::EXIT_FAILURE;
    }
    try {
      $mode = ErasureMode::fromOption((string) $options['mode']);
    }
    catch (\InvalidArgumentException $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }
    $dryRun = (bool) $options['dry-run'];
    if (!$dryRun && !$this->io()->confirm(dt('@mode uid @uid (@name)? This cannot be undone.', [
      '@mode' => ucfirst($mode->value),
      '@uid' => $user->id(),
      '@name' => $user->getAccountName(),
    ]))) {
      $this->logger()->warning(dt('Cancelled.'));
      return self::EXIT_FAILURE_WITH_CLARITY;
    }
    try {
      $report = $this->eraser->erase($user, $mode, $dryRun, (bool) $options['force']);
    }
    catch (\Throwable $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }
    $rows = [];
    foreach ($report->sections as $id => $section) {
      $rows[] = [$id, $section['module'], $section['count'], $section['note']];
    }
    $this->io()->table(['Source', 'Module', 'Rows', 'Action'], $rows);
    $this->io()->writeln(($dryRun ? 'Dry run - account would be: ' : 'Account: ') . $report->accountAction);
    $this->logger()->success(dt('@prefix@total rows across @n sources for uid @uid (@mode).', [
      '@prefix' => $dryRun ? 'Dry run: ' : '',
      '@total' => $report->total(),
      '@n' => count($report->sections),
      '@uid' => $report->uid,
      '@mode' => $mode->value,
    ]));
    return self::EXIT_SUCCESS;
  }

  /**
   * Lists the data collectors and how many rows each holds for a user.
   *
   * @param array<string,mixed> $options
   */
  #[CLI\Command(name: 'aabenintra:gdpr-inventory', aliases: ['gdpr-inventory'])]
  #[CLI\Argument(name: 'subject', description: 'User id, e-mail address or account name. Omit to list collectors only.')]
  #[CLI\FieldLabels(labels: ['id' => 'Source', 'module' => 'Module', 'label' => 'Label', 'active' => 'Active', 'count' => 'Rows'])]
  #[CLI\DefaultTableFields(fields: ['id', 'module', 'active', 'count'])]
  #[CLI\Usage(name: 'drush aabenintra:gdpr-inventory 42', description: 'Row counts per source for uid 42.')]
  #[CLI\Usage(name: 'drush aabenintra:gdpr-inventory --format=json', description: 'Registered collectors and whether their module is installed.')]
  public function inventory(?string $subject = NULL, array $options = ['format' => 'table']): RowsOfFields {
    $user = NULL;
    if ($subject !== NULL) {
      $user = $this->resolve($subject);
      if ($user === NULL) {
        throw new \RuntimeException(dt('No user matches "@subject".', ['@subject' => $subject]));
      }
    }
    $rows = [];
    foreach ($this->collectors->all() as $collector) {
      $active = $collector->applies();
      $rows[] = [
        'id' => $collector->id(),
        'module' => $collector->module(),
        'label' => $collector->label(),
        'active' => $active ? 'yes' : 'no (module not installed)',
        'count' => $active && $user !== NULL ? $collector->count($user) : '-',
      ];
    }
    return new RowsOfFields($rows);
  }

  private function resolve(string $subject): ?UserInterface {
    $user = $this->resolver->resolve($subject);
    if ($user === NULL) {
      $this->logger()->error(dt('No user matches "@subject".', ['@subject' => $subject]));
    }
    return $user;
  }

}
