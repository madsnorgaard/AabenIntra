<?php

declare(strict_types=1);

namespace Drupal\aabenintra_ops\Check\Validate;

use Drupal\aabenintra_ops\Check\CheckResult;
use Drupal\aabenintra_ops\Check\HealthCheckInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Renders the configured pages through an HttpKernel sub-request.
 *
 * Each page must return 200 and contain its marker (a wrapper class from the
 * page template), which proves routing, the controller, the theme registry
 * and the template chain all work on this tenant. The requests run as the
 * configured account (uid 1 by default) via the account switcher: no account
 * is created or modified, and the switch is undone in a finally block.
 *
 * A SUB_REQUEST bypasses the page cache and dynamic page cache (both only act
 * on the main request), so nothing rendered for uid 1 can leak into a cache
 * served to other users.
 */
final class SyntheticPageCheck implements HealthCheckInterface {

  public function __construct(
    private readonly HttpKernelInterface $httpKernel,
    private readonly RequestStack $requestStack,
    private readonly AccountSwitcherInterface $accountSwitcher,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'synthetic_pages';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Synthetic page requests';
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
    $settings = $this->configFactory->get('aabenintra_ops.settings');
    $pages = $settings->get('synthetic_pages');
    if (!is_array($pages) || $pages === []) {
      return CheckResult::skipped('No synthetic_pages configured in aabenintra_ops.settings.');
    }
    $uid = (int) ($settings->get('synthetic_uid') ?? 1);
    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$account instanceof AccountInterface) {
      return CheckResult::fail(sprintf('User %d does not exist; cannot run synthetic requests.', $uid));
    }
    if (!$account->hasPermission('access content')) {
      return CheckResult::fail(sprintf('User %d lacks "access content"; synthetic requests would be denied.', $uid));
    }

    $current = $this->requestStack->getCurrentRequest();
    $base = $current !== NULL ? $current->getSchemeAndHttpHost() : 'http://default';

    $details = [];
    $problems = [];
    $this->accountSwitcher->switchTo($account);
    try {
      foreach ($pages as $page) {
        $path = (string) ($page['path'] ?? '');
        $marker = (string) ($page['marker'] ?? '');
        if ($path === '') {
          continue;
        }
        $request = Request::create($base . $path, 'GET');
        if ($current !== NULL && $current->hasSession()) {
          $request->setSession($current->getSession());
        }
        try {
          $response = $this->httpKernel->handle($request, HttpKernelInterface::SUB_REQUEST, FALSE);
        }
        catch (\Throwable $e) {
          $problems[] = sprintf('%s threw %s: %s', $path, get_class($e), $e->getMessage());
          $details[$path] = 'exception';
          continue;
        }
        $status = $response->getStatusCode();
        $body = (string) $response->getContent();
        $details[$path] = (string) $status;
        if ($status !== 200) {
          $problems[] = sprintf('%s returned HTTP %d', $path, $status);
        }
        elseif ($marker !== '' && !str_contains($body, $marker)) {
          $problems[] = sprintf('%s rendered without marker "%s"', $path, $marker);
          $details[$path] = '200 (no marker)';
        }
      }
    }
    finally {
      $this->accountSwitcher->switchBack();
    }

    if ($problems !== []) {
      return CheckResult::fail(implode('; ', $problems) . '.', $details);
    }
    return CheckResult::ok(sprintf('%d page(s) rendered with HTTP 200 and their markers.', count($details)), $details);
  }

}
