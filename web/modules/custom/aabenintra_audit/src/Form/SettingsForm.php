<?php

declare(strict_types=1);

namespace Drupal\aabenintra_audit\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Retention, IP anonymisation and alert settings.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'aabenintra_audit_settings';
  }

  /**
   * {@inheritdoc}
   *
   * @return string[]
   */
  protected function getEditableConfigNames(): array {
    return ['aabenintra_audit.settings'];
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string,mixed> $form
   *
   * @return array<string,mixed>
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('aabenintra_audit.settings');

    $form['retention'] = [
      '#type' => 'details',
      '#title' => $this->t('Retention and data minimisation'),
      '#open' => TRUE,
    ];
    $form['retention']['retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Delete audit rows after (days)'),
      '#description' => $this->t('Rows older than this are deleted by cron. 0 keeps them forever (not recommended under GDPR storage limitation).'),
      '#min' => 0,
      '#max' => 3650,
      '#default_value' => $config->get('retention_days'),
      '#required' => TRUE,
    ];
    $form['retention']['anonymise_ip_after_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Anonymise IP addresses after (days)'),
      '#description' => $this->t('The last IPv4 octet / IPv6 host part is zeroed on rows older than this. 0 disables anonymisation.'),
      '#min' => 0,
      '#max' => 3650,
      '#default_value' => $config->get('anonymise_ip_after_days'),
      '#required' => TRUE,
    ];

    $form['alerts'] = [
      '#type' => 'details',
      '#title' => $this->t('Suspicious-login alerts'),
      '#open' => TRUE,
    ];
    $form['alerts']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send alerts'),
      '#default_value' => (bool) $config->get('alerts.enabled'),
    ];
    $form['alerts']['mail'] = [
      '#type' => 'email',
      '#title' => $this->t('Recipient address'),
      '#description' => $this->t('A security or operations mailbox. Alerts are also written to the Drupal log and the audit log itself.'),
      '#default_value' => $config->get('alerts.mail'),
      '#states' => ['required' => [':input[name="enabled"]' => ['checked' => TRUE]]],
    ];
    $form['alerts']['failed_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Failed attempts from one IP'),
      '#min' => 1,
      '#max' => 1000,
      '#default_value' => $config->get('alerts.failed_threshold'),
    ];
    $form['alerts']['failed_window'] = [
      '#type' => 'number',
      '#title' => $this->t('... within (seconds)'),
      '#min' => 60,
      '#max' => 86400,
      '#default_value' => $config->get('alerts.failed_window'),
    ];
    $form['alerts']['new_ip_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Alert on login from an IP not used by that user within (days)'),
      '#description' => $this->t('Should not exceed the IP anonymisation period, or anonymised rows will no longer match.'),
      '#min' => 1,
      '#max' => 3650,
      '#default_value' => $config->get('alerts.new_ip_days'),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   *
   * @param mixed[] $form
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    if ($form_state->getValue('enabled') && trim((string) $form_state->getValue('mail')) === '') {
      $form_state->setErrorByName('mail', $this->t('A recipient address is required when alerts are enabled.'));
    }
  }

  /**
   * {@inheritdoc}
   *
   * @param mixed[] $form
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('aabenintra_audit.settings')
      ->set('retention_days', (int) $form_state->getValue('retention_days'))
      ->set('anonymise_ip_after_days', (int) $form_state->getValue('anonymise_ip_after_days'))
      ->set('alerts.enabled', (bool) $form_state->getValue('enabled'))
      ->set('alerts.mail', trim((string) $form_state->getValue('mail')))
      ->set('alerts.failed_threshold', (int) $form_state->getValue('failed_threshold'))
      ->set('alerts.failed_window', (int) $form_state->getValue('failed_window'))
      ->set('alerts.new_ip_days', (int) $form_state->getValue('new_ip_days'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
