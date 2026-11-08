<?php

declare(strict_types=1);

namespace Drupal\aabenintra_security\Csp;

/**
 * Builds a Content-Security-Policy header value.
 *
 * Pure PHP, no container dependencies, so the policy can be unit tested and
 * reasoned about in isolation. The baseline below is what a stock AabenIntra
 * tenant needs; operators append sources per directive through configuration
 * (see docs/SECURITY-HEADERS.md).
 */
final class PolicyBuilder {

  /**
   * Fetch directives in the order they are emitted.
   */
  public const DIRECTIVES = [
    'default-src',
    'script-src',
    'style-src',
    'img-src',
    'font-src',
    'connect-src',
    'media-src',
    'frame-src',
    'frame-ancestors',
    'worker-src',
    'manifest-src',
    'base-uri',
    'form-action',
    'object-src',
  ];

  /**
   * Baseline sources per directive for a stock tenant.
   *
   * - script-src 'self': core emits no executable inline scripts. drupalSettings
   *   is a <script type="application/json"> and BigPipe placeholders are
   *   <script type="application/vnd.drupal-ajax">; browsers do not execute
   *   either, so they need no nonce.
   * - style-src 'unsafe-inline': the theme and the dashboard set accent colours
   *   through style="--token: ..." attributes, aabenintra_theme writes an
   *   inline --accent on <html>, and CKEditor 5 / core dialogs set style
   *   attributes at runtime. Style attributes cannot be nonced, so this is
   *   configurable rather than hard-coded (csp.style_unsafe_inline).
   * - img-src / media-src data: blob: for CKEditor previews, image uploads and
   *   generated avatars; font-src data: for inlined icon fonts.
   * - worker-src blob: for the Vite-built islands that may spawn workers.
   */
  public const BASELINE = [
    'default-src' => ["'self'"],
    'script-src' => ["'self'"],
    'style-src' => ["'self'", "'unsafe-inline'"],
    'img-src' => ["'self'", 'data:', 'blob:'],
    'font-src' => ["'self'", 'data:'],
    'connect-src' => ["'self'"],
    'media-src' => ["'self'", 'data:', 'blob:'],
    'frame-src' => ["'self'"],
    'frame-ancestors' => ["'self'"],
    'worker-src' => ["'self'", 'blob:'],
    'manifest-src' => ["'self'"],
    'base-uri' => ["'self'"],
    'form-action' => ["'self'"],
    'object-src' => ["'none'"],
  ];

  /**
   * Name of the Reporting-Endpoints group used for report-to.
   */
  public const REPORT_GROUP = 'csp-endpoint';

  /**
   * Converts a directive name to its configuration key (script-src -> script_src).
   */
  public static function configKey(string $directive): string {
    return str_replace('-', '_', $directive);
  }

  /**
   * Returns the baseline directive map.
   *
   * @return array<string, list<string>>
   *   Directive => source list.
   */
  public function baseline(bool $styleUnsafeInline = TRUE): array {
    $baseline = self::BASELINE;
    if (!$styleUnsafeInline) {
      $baseline['style-src'] = array_values(array_diff($baseline['style-src'], ["'unsafe-inline'"]));
    }
    return $baseline;
  }

  /**
   * Builds the header value.
   *
   * @param array<string, list<string>> $extra
   *   Extra sources keyed by directive name ('script-src') or config key
   *   ('script_src'). Unknown directives are ignored.
   * @param bool $styleUnsafeInline
   *   Keep 'unsafe-inline' in style-src.
   * @param bool $upgradeInsecureRequests
   *   Append upgrade-insecure-requests.
   * @param string|null $nonce
   *   A per-request nonce to add to script-src, or NULL for none.
   * @param string $reportUri
   *   Violation report endpoint; empty for none. Emits both report-uri (widely
   *   supported) and report-to (paired with a Reporting-Endpoints header).
   *
   * @return string
   *   The policy, directives separated by "; ".
   */
  public function build(array $extra = [], bool $styleUnsafeInline = TRUE, bool $upgradeInsecureRequests = TRUE, ?string $nonce = NULL, string $reportUri = ''): string {
    $parts = [];
    foreach ($this->directives($extra, $styleUnsafeInline, $nonce) as $directive => $sources) {
      $parts[] = $directive . ' ' . implode(' ', $sources);
    }
    if ($upgradeInsecureRequests) {
      $parts[] = 'upgrade-insecure-requests';
    }
    $reportUri = self::sanitizeReportUri($reportUri);
    if ($reportUri !== '') {
      $parts[] = 'report-uri ' . $reportUri;
      $parts[] = 'report-to ' . self::REPORT_GROUP;
    }
    return implode('; ', $parts);
  }

  /**
   * Resolves the effective directive map (baseline + extras + nonce).
   *
   * @param array<string, list<string>> $extra
   *   Extra sources keyed by directive name or config key.
   * @param bool $styleUnsafeInline
   *   Keep 'unsafe-inline' in style-src.
   * @param string|null $nonce
   *   Nonce for script-src, or NULL.
   *
   * @return array<string, list<string>>
   *   Directive => de-duplicated, sanitised source list, in emission order.
   */
  public function directives(array $extra = [], bool $styleUnsafeInline = TRUE, ?string $nonce = NULL): array {
    $baseline = $this->baseline($styleUnsafeInline);
    $result = [];
    foreach (self::DIRECTIVES as $directive) {
      $sources = $baseline[$directive];
      $added = $extra[$directive] ?? $extra[self::configKey($directive)] ?? [];
      foreach ($added as $source) {
        $source = self::sanitizeSource((string) $source);
        if ($source !== NULL) {
          $sources[] = $source;
        }
      }
      if ($directive === 'script-src' && $nonce !== NULL && $nonce !== '') {
        $sources[] = "'nonce-" . $nonce . "'";
      }
      $sources = array_values(array_unique($sources));
      // 'none' is only meaningful on its own; an operator adding a source to
      // object-src means they want that source allowed.
      if (count($sources) > 1) {
        $sources = array_values(array_diff($sources, ["'none'"]));
      }
      $result[$directive] = $sources;
    }
    return $result;
  }

  /**
   * Builds the Reporting-Endpoints header value for the report-to group.
   */
  public static function reportingEndpoints(string $reportUri): string {
    $reportUri = self::sanitizeReportUri($reportUri);
    return $reportUri === '' ? '' : self::REPORT_GROUP . '="' . $reportUri . '"';
  }

  /**
   * Accepts a single CSP source expression or rejects it.
   *
   * Source expressions never contain whitespace, ';' or ',' - those would let
   * a configuration value smuggle extra directives into the header.
   */
  public static function sanitizeSource(string $source): ?string {
    $source = trim($source);
    if ($source === '' || preg_match('/^[^\s;,]+$/', $source) !== 1) {
      return NULL;
    }
    return $source;
  }

  /**
   * Accepts an absolute https URL or a site-relative path for report-uri.
   */
  public static function sanitizeReportUri(string $uri): string {
    $uri = trim($uri);
    if ($uri === '' || preg_match('/^(https:\/\/[^\s;,"]+|\/[^\s;,"]*)$/', $uri) !== 1) {
      return '';
    }
    return $uri;
  }

}
