<?php

declare(strict_types=1);

namespace Drupal\aabenintra_ops\Check\Validate;

use Drupal\aabenintra_ops\Check\CheckResult;
use Drupal\aabenintra_ops\Check\HealthCheckInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Mail\MailManagerInterface;

/**
 * Outgoing mail is configured and the configured mail plugin exists.
 *
 * Settings.php switches system.mail to SMTPMailSystem when SMTP_PASSWORD is
 * set in the environment; that plugin only exists once drupal/smtp is
 * installed, so a tenant with the env var but without the module would fail
 * every mail. Plain PHP mail() is reported as warn: from a container it is
 * almost never deliverable.
 */
final class MailCheck implements HealthCheckInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly MailManagerInterface $mailManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'mail';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Outgoing mail configured';
  }

  /**
   * {@inheritdoc}
   */
  public function groups(): array {
    return [self::GROUP_VALIDATE];
  }

  /**
   * {@inheritdoc}
   */
  public function run(): CheckResult {
    $plugin = (string) ($this->configFactory->get('system.mail')->get('interface.default') ?? 'php_mail');
    if (!$this->mailManager->hasDefinition($plugin)) {
      return CheckResult::fail(sprintf('system.mail uses plugin "%s" which does not exist (SMTP_PASSWORD set but the smtp module is not installed?).', $plugin), ['plugin' => $plugin]);
    }
    if ($plugin === 'php_mail') {
      return CheckResult::warn('Mail goes through PHP mail(); no SMTP relay is configured for this tenant.', ['plugin' => $plugin]);
    }
    $details = ['plugin' => $plugin];
    if ($this->moduleHandler->moduleExists('smtp')) {
      $smtp = $this->configFactory->get('smtp.settings');
      $details['smtp_on'] = (bool) $smtp->get('smtp_on');
      $details['smtp_host'] = (string) ($smtp->get('smtp_host') ?? '');
      $details['smtp_port'] = (string) ($smtp->get('smtp_port') ?? '');
      if (!$smtp->get('smtp_on') || $details['smtp_host'] === '') {
        return CheckResult::fail('smtp module is the mail plugin but smtp_on is off or smtp_host is empty.', $details);
      }
    }
    return CheckResult::ok('Mail is routed through ' . $plugin . '.', $details);
  }

}
