<?php

declare(strict_types=1);

namespace Drupal\aabenintra_gdpr\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Retention, reassignment target and request rate limit.
 */
final class SettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager'),
    );
  }

  public function getFormId(): string {
    return 'aabenintra_gdpr_settings';
  }

  /**
   * @return list<string>
   */
  protected function getEditableConfigNames(): array {
    return ['aabenintra_gdpr.settings'];
  }

  /**
   * @param array<string,mixed> $form
   *
   * @return array<string,mixed>
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('aabenintra_gdpr.settings');
    $reassign_uid = (int) $config->get('reassign_uid');
    $form['retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Export retention (days)'),
      '#description' => $this->t('Export files in the private file system are deleted by cron after this many days. Download links expire at the same time.'),
      '#min' => 1,
      '#max' => 90,
      '#default_value' => $config->get('retention_days') ?? 7,
      '#required' => TRUE,
    ];
    $form['reassign_uid'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'user',
      '#title' => $this->t('Reassign content to'),
      '#description' => $this->t('When a user is anonymised their content is kept and re-attributed to this account. Leave empty for the anonymous user (same as core account cancellation).'),
      '#default_value' => $reassign_uid > 0 ? $this->entityTypeManager->getStorage('user')->load($reassign_uid) : NULL,
      '#selection_settings' => ['include_anonymous' => FALSE],
    ];
    $form['request_rate_limit_hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Hours between self-service export requests'),
      '#description' => $this->t('A user can queue one export per this many hours. 0 disables the limit.'),
      '#min' => 0,
      '#max' => 720,
      '#default_value' => $config->get('request_rate_limit_hours') ?? 24,
      '#required' => TRUE,
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * @param mixed[] $form
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('aabenintra_gdpr.settings')
      ->set('retention_days', (int) $form_state->getValue('retention_days'))
      ->set('reassign_uid', (int) ($form_state->getValue('reassign_uid') ?? 0))
      ->set('request_rate_limit_hours', (int) $form_state->getValue('request_rate_limit_hours'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
