<?php

declare(strict_types=1);

namespace Drupal\aabenintra_audit\Form;

use Drupal\aabenintra_audit\AuditEvents;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * GET filter form for the audit log (event, user, IP, date range).
 *
 * Submitting reloads the report route with the filters as query parameters,
 * which keeps them in the pager links and the CSV export link.
 */
final class AuditFilterForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'aabenintra_audit_filter_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string,mixed> $form
   *
   * @return array<string,mixed>
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $query = $this->getRequest()->query;
    $form['#method'] = 'get';
    $form['#action'] = Url::fromRoute('aabenintra_audit.report')->toString();
    $form['#token'] = FALSE;
    $form['#after_build'][] = '::stripFormInternals';
    $form['#attributes']['class'][] = 'aabenintra-audit-filters';

    $form['filters'] = [
      '#type' => 'details',
      '#title' => $this->t('Filter'),
      '#open' => TRUE,
      '#attributes' => ['class' => ['container-inline']],
    ];
    $events = ['' => $this->t('- Any event -')];
    foreach (AuditEvents::all() as $event) {
      $events[$event] = $event;
    }
    $form['filters']['event'] = [
      '#type' => 'select',
      '#title' => $this->t('Event'),
      '#options' => $events,
      '#default_value' => (string) $query->get('event', ''),
    ];
    $form['filters']['user'] = [
      '#type' => 'textfield',
      '#title' => $this->t('User'),
      '#description' => $this->t('Account name or numeric uid.'),
      '#size' => 20,
      '#default_value' => (string) $query->get('user', ''),
    ];
    $form['filters']['ip'] = [
      '#type' => 'textfield',
      '#title' => $this->t('IP'),
      '#description' => $this->t('Exact address, or a prefix ending in * (e.g. 10.0.*).'),
      '#size' => 20,
      '#default_value' => (string) $query->get('ip', ''),
    ];
    $form['filters']['from'] = [
      '#type' => 'date',
      '#title' => $this->t('From'),
      '#default_value' => (string) $query->get('from', ''),
    ];
    $form['filters']['to'] = [
      '#type' => 'date',
      '#title' => $this->t('To'),
      '#default_value' => (string) $query->get('to', ''),
    ];
    $form['filters']['actions'] = ['#type' => 'actions'];
    $form['filters']['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply'),
    ];
    $form['filters']['actions']['reset'] = [
      '#type' => 'link',
      '#title' => $this->t('Reset'),
      '#url' => Url::fromRoute('aabenintra_audit.report'),
      '#attributes' => ['class' => ['button']],
    ];
    return $form;
  }

  /**
   * Hides the hidden form bookkeeping so it does not pollute the query string.
   *
   * @param array<string,mixed> $form
   *
   * @return array<string,mixed>
   */
  public function stripFormInternals(array $form, FormStateInterface $form_state): array {
    foreach (['form_build_id', 'form_token', 'form_id'] as $key) {
      if (isset($form[$key])) {
        $form[$key]['#access'] = FALSE;
      }
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * Never reached: the browser submits a GET to the report route and the
   * controller reads the query string via filtersFromQuery().
   *
   * @param mixed[] $form
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  /**
   * Translates the query string into AuditRepository::select() filters.
   *
   * @param array<string,mixed> $query
   *
   * @return array<string,mixed>
   */
  public static function filtersFromQuery(array $query): array {
    $filters = [];
    $event = trim((string) ($query['event'] ?? ''));
    if ($event !== '' && preg_match('/^[a-z0-9_]{1,64}$/', $event) === 1) {
      $filters['event'] = $event;
    }
    $user = trim((string) ($query['user'] ?? ''));
    if ($user !== '') {
      if (ctype_digit($user)) {
        $filters['uid'] = (int) $user;
      }
      else {
        $filters['username'] = mb_substr($user, 0, 255);
      }
    }
    $ip = trim((string) ($query['ip'] ?? ''));
    if ($ip !== '' && preg_match('/^[0-9a-fA-F:.]{1,45}\*?$/', $ip) === 1) {
      $filters['ip'] = $ip;
    }
    $from = self::parseDate((string) ($query['from'] ?? ''), FALSE);
    if ($from !== NULL) {
      $filters['from'] = $from;
    }
    $to = self::parseDate((string) ($query['to'] ?? ''), TRUE);
    if ($to !== NULL) {
      $filters['to'] = $to;
    }
    return $filters;
  }

  /**
   * Parses a YYYY-MM-DD value in the site timezone; end-of-day when $end.
   */
  private static function parseDate(string $value, bool $end): ?int {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
      return NULL;
    }
    try {
      $date = new \DateTimeImmutable($value . ($end ? ' 23:59:59' : ' 00:00:00'), new \DateTimeZone(date_default_timezone_get()));
    }
    catch (\Exception) {
      return NULL;
    }
    return $date->getTimestamp();
  }

}
