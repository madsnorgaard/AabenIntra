<?php

declare(strict_types=1);

namespace Drupal\aabenintra_security\Form;

use Drupal\aabenintra_security\Csp\PolicyBuilder;
use Drupal\aabenintra_security\EventSubscriber\SecurityHeadersSubscriber;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Operator settings for the security response headers.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * Referrer-Policy values offered in the form.
   */
  private const REFERRER_POLICIES = [
    'no-referrer',
    'no-referrer-when-downgrade',
    'same-origin',
    'origin',
    'strict-origin',
    'origin-when-cross-origin',
    'strict-origin-when-cross-origin',
  ];

  /**
   * Not promoted/readonly: ConfigFormBase uses DependencySerializationTrait.
   */
  protected PolicyBuilder $policyBuilder;

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    PolicyBuilder $policyBuilder,
  ) {
    parent::__construct($config_factory, $typedConfigManager);
    $this->policyBuilder = $policyBuilder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('aabenintra_security.policy_builder'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'aabenintra_security_settings';
  }

  /**
   * {@inheritdoc}
   *
   * @return list<string>
   *   Editable config names.
   */
  protected function getEditableConfigNames(): array {
    return [SecurityHeadersSubscriber::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(SecurityHeadersSubscriber::CONFIG_NAME);

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Emit security headers'),
      '#default_value' => (bool) $config->get('enabled'),
      '#description' => $this->t('Master switch. X-Content-Type-Options and X-Frame-Options are set by Drupal core regardless.'),
    ];

    $form['hsts'] = [
      '#type' => 'details',
      '#title' => $this->t('Strict-Transport-Security (HSTS)'),
      '#open' => TRUE,
    ];
    $form['hsts']['hsts_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send HSTS on HTTPS responses'),
      '#default_value' => (bool) $config->get('hsts.enabled'),
      '#description' => $this->t('max-age is fixed at one year (31536000 seconds) with includeSubDomains. Never sent on plain HTTP.'),
    ];
    $form['hsts']['hsts_preload'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Add preload'),
      '#default_value' => (bool) $config->get('hsts.preload'),
      '#description' => $this->t('Only enable after every subdomain of the site domain serves HTTPS, and only if you intend to submit the domain to the browser preload list. Preload cannot be undone quickly.'),
      '#states' => ['visible' => [':input[name="hsts_enabled"]' => ['checked' => TRUE]]],
    ];

    $form['referrer_policy'] = [
      '#type' => 'select',
      '#title' => $this->t('Referrer-Policy'),
      '#options' => ['' => $this->t('- Do not send -')] + array_combine(self::REFERRER_POLICIES, self::REFERRER_POLICIES),
      '#default_value' => (string) $config->get('referrer_policy'),
    ];

    $form['permissions_policy'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Permissions-Policy'),
      '#default_value' => (string) $config->get('permissions_policy'),
      '#maxlength' => 1024,
      '#description' => $this->t('Comma-separated feature list, for example <code>camera=(), microphone=(), geolocation=()</code>. Leave empty to not send the header.'),
    ];

    $form['csp'] = [
      '#type' => 'details',
      '#title' => $this->t('Content-Security-Policy'),
      '#open' => TRUE,
    ];
    $form['csp']['csp_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send a Content-Security-Policy'),
      '#default_value' => (bool) $config->get('csp.enabled'),
    ];
    $form['csp']['report_only'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Report only (do not enforce)'),
      '#default_value' => (bool) $config->get('csp.report_only'),
      '#description' => $this->t('Sends Content-Security-Policy-Report-Only. Browsers log violations (console, and the report endpoint below) but block nothing. Keep this on until the reports are clean, then switch to enforcing.'),
    ];
    $form['csp']['report_uri'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Violation report endpoint'),
      '#default_value' => (string) $config->get('csp.report_uri'),
      '#maxlength' => 512,
      '#description' => $this->t('Absolute https URL or site-relative path. Emitted as both report-uri and report-to (with a Reporting-Endpoints header). Leave empty to rely on the browser console.'),
    ];
    $form['csp']['style_unsafe_inline'] = [
      '#type' => 'checkbox',
      '#title' => $this->t("Allow inline styles ('unsafe-inline' in style-src)"),
      '#default_value' => (bool) $config->get('csp.style_unsafe_inline'),
      '#description' => $this->t('Required by the theme (accent colours via style attributes) and by CKEditor 5. Turn off only after verifying in report-only mode that no style violations remain.'),
    ];
    $form['csp']['upgrade_insecure_requests'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Add upgrade-insecure-requests'),
      '#default_value' => (bool) $config->get('csp.upgrade_insecure_requests'),
    ];
    $form['csp']['use_nonce'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Add a per-request nonce to script-src'),
      '#default_value' => (bool) $config->get('csp.use_nonce'),
      '#description' => $this->t('Only needed if a template emits an inline script tagged with <code>nonce="{{ csp_nonce() }}"</code>. Stock AabenIntra has none. Pages that render a nonce are not cacheable.'),
    ];

    $baseline = $this->policyBuilder->baseline((bool) $config->get('csp.style_unsafe_inline'));
    $form['csp']['extra'] = [
      '#type' => 'details',
      '#title' => $this->t('Extra sources per directive'),
      '#open' => FALSE,
      '#description' => $this->t('One source expression per line, appended to the built-in baseline shown under each field. Examples: <code>https://login.example.dk</code>, <code>https://*.sharepoint.com</code>, <code>wss://chat.example.dk</code>.'),
      '#tree' => TRUE,
    ];
    /** @var array<string, list<string>> $extra */
    $extra = $config->get('csp.extra') ?? [];
    foreach (PolicyBuilder::DIRECTIVES as $directive) {
      $key = PolicyBuilder::configKey($directive);
      $form['csp']['extra'][$key] = [
        '#type' => 'textarea',
        '#title' => $directive,
        '#rows' => 2,
        '#default_value' => implode("\n", $extra[$key] ?? []),
        '#description' => $this->t('Baseline: @baseline', ['@baseline' => implode(' ', $baseline[$directive])]),
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   *
   * @param mixed[] $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $reportUri = trim((string) $form_state->getValue('report_uri'));
    if ($reportUri !== '' && PolicyBuilder::sanitizeReportUri($reportUri) === '') {
      $form_state->setErrorByName('report_uri', $this->t('The report endpoint must be an absolute https:// URL or a path starting with /.'));
    }

    $permissions = trim((string) $form_state->getValue('permissions_policy'));
    if ($permissions !== '' && preg_match('/^[A-Za-z0-9\-=(),"\':\/.* ]+$/', $permissions) !== 1) {
      $form_state->setErrorByName('permissions_policy', $this->t('The Permissions-Policy value contains characters that are not allowed in a header.'));
    }

    foreach (PolicyBuilder::DIRECTIVES as $directive) {
      $key = PolicyBuilder::configKey($directive);
      foreach (self::linesToList((string) $form_state->getValue(['extra', $key])) as $line) {
        if (PolicyBuilder::sanitizeSource($line) === NULL) {
          $form_state->setErrorByName('extra][' . $key, $this->t('"@source" is not a valid source expression for @directive (no spaces, semicolons or commas).', [
            '@source' => $line,
            '@directive' => $directive,
          ]));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   *
   * @param mixed[] $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $extra = [];
    foreach (PolicyBuilder::DIRECTIVES as $directive) {
      $key = PolicyBuilder::configKey($directive);
      $extra[$key] = self::linesToList((string) $form_state->getValue(['extra', $key]));
    }

    $this->config(SecurityHeadersSubscriber::CONFIG_NAME)
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->set('hsts.enabled', (bool) $form_state->getValue('hsts_enabled'))
      ->set('hsts.preload', (bool) $form_state->getValue('hsts_preload'))
      ->set('referrer_policy', (string) $form_state->getValue('referrer_policy'))
      ->set('permissions_policy', trim((string) $form_state->getValue('permissions_policy')))
      ->set('csp.enabled', (bool) $form_state->getValue('csp_enabled'))
      ->set('csp.report_only', (bool) $form_state->getValue('report_only'))
      ->set('csp.report_uri', trim((string) $form_state->getValue('report_uri')))
      ->set('csp.style_unsafe_inline', (bool) $form_state->getValue('style_unsafe_inline'))
      ->set('csp.upgrade_insecure_requests', (bool) $form_state->getValue('upgrade_insecure_requests'))
      ->set('csp.use_nonce', (bool) $form_state->getValue('use_nonce'))
      ->set('csp.extra', $extra)
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Splits a textarea into a trimmed, de-duplicated list of non-empty lines.
   *
   * @return list<string>
   *   The lines.
   */
  private static function linesToList(string $text): array {
    $lines = array_map('trim', preg_split('/\R/', $text) ?: []);
    return array_values(array_unique(array_filter($lines, static fn(string $line): bool => $line !== '')));
  }

}
