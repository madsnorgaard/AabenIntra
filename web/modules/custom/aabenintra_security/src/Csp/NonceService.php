<?php

declare(strict_types=1);

namespace Drupal\aabenintra_security\Csp;

/**
 * Holds the per-request CSP nonce.
 *
 * The nonce is generated lazily and tracked as "consumed" once a template has
 * asked for it. The response subscriber only puts the nonce into script-src
 * when it was consumed or when the response can never be served from the
 * anonymous page cache - a nonce baked into a cached header would be reusable
 * by anyone who can read the cached response and so give no protection.
 *
 * Drupal 11 core has no nonce support of its own; this is the project's
 * minimal implementation and is off by default (csp.use_nonce).
 */
final class NonceService {

  /**
   * The nonce for the current request, NULL until first requested.
   */
  private ?string $nonce = NULL;

  /**
   * Whether markup rendered during this request depends on the nonce.
   */
  private bool $consumed = FALSE;

  /**
   * Returns the nonce for this request, generating it on first call.
   */
  public function getNonce(): string {
    if ($this->nonce === NULL) {
      $this->nonce = base64_encode(random_bytes(18));
    }
    return $this->nonce;
  }

  /**
   * Returns the nonce and records that markup depends on it.
   */
  public function consume(): string {
    $this->consumed = TRUE;
    return $this->getNonce();
  }

  /**
   * Whether a template has asked for the nonce during this request.
   */
  public function isConsumed(): bool {
    return $this->consumed;
  }

  /**
   * Forgets the nonce so the next request gets a fresh one.
   */
  public function reset(): void {
    $this->nonce = NULL;
    $this->consumed = FALSE;
  }

}
