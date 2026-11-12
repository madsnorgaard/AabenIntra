<?php

declare(strict_types=1);

namespace Drupal\aabenintra_security\Twig;

use Drupal\aabenintra_security\Csp\NonceService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes csp_nonce() to Twig.
 *
 * Usage: <script nonce="{{ csp_nonce() }}">...</script>
 *
 * The function returns a render array with max-age 0 rather than a string so
 * that Drupal's Twig escaping bubbles the "uncacheable" metadata into the
 * render context: a template containing a nonce must never be stored in the
 * render cache, the dynamic page cache or the page cache, otherwise the
 * cached markup would carry a stale nonce that no longer matches the header.
 */
final class CspNonceExtension extends AbstractExtension {

  public function __construct(
    private readonly NonceService $nonce,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFunctions(): array {
    return [
      new TwigFunction('csp_nonce', [$this, 'nonce']),
    ];
  }

  /**
   * Returns the per-request nonce as an uncacheable render array.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  public function nonce(): array {
    return [
      '#plain_text' => $this->nonce->consume(),
      '#cache' => ['max-age' => 0],
    ];
  }

}
