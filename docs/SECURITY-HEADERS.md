# Security response headers

Module: `web/modules/custom/aabenintra_security` (enabled by `scripts/install_prod.sh`).
Settings: `/admin/config/system/aabenintra-security` (permission *administer site configuration*),
config object `aabenintra_security.settings`. Resolves issue #114.

## What is set

Every main-request response (HTML, JSON, redirects, file downloads) gets:

| Header | Default value | Source |
|---|---|---|
| `X-Content-Type-Options` | `nosniff` | Drupal core (`FinishResponseSubscriber`); the module only fills it in if missing |
| `X-Frame-Options` | `SAMEORIGIN` | Drupal core; same rule. Browsers that understand `frame-ancestors` ignore this header |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` (optional `; preload`) | Module, only on HTTPS requests |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Module |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()` | Module |
| `Content-Security-Policy-Report-Only` | the policy below | Module, until `report_only` is turned off |
| `Reporting-Endpoints` | `csp-endpoint="<report_uri>"` | Module, only when a report endpoint is configured |

Headers already present on the response (set by another module or a controller)
are never overwritten. The settings config is attached to the response
cacheability, so saving the form invalidates the page cache and the new policy
is live immediately.

HSTS is detected via `Request::isSecure()`. Behind Traefik that works because
`settings.php` sets `$settings['reverse_proxy'] = true`, so the
`X-Forwarded-Proto` header from the proxy is trusted. The header is never sent
on plain HTTP (browsers ignore it there anyway).

## Default Content-Security-Policy

```
default-src 'self';
script-src 'self';
style-src 'self' 'unsafe-inline';
img-src 'self' data: blob:;
font-src 'self' data:;
connect-src 'self';
media-src 'self' data: blob:;
frame-src 'self';
frame-ancestors 'self';
worker-src 'self' blob:;
manifest-src 'self';
base-uri 'self';
form-action 'self';
object-src 'none';
upgrade-insecure-requests
```

Why these values:

- `script-src 'self'` with no `'unsafe-inline'`: Drupal 11 core emits no
  executable inline scripts. `drupalSettings` is a
  `<script type="application/json" data-drupal-selector="drupal-settings-json">`
  and BigPipe placeholders are `<script type="application/vnd.drupal-ajax">`;
  browsers do not execute either type, so they need no nonce. The theme's Vite
  bundle (`dist/main.js`) and all module JS are external files served from the
  site origin. A grep of `web/themes/custom/aabenintra/templates`,
  `web/modules/custom/*/templates` and `*.module`/`*.theme` finds no `<script>`
  blocks and no `on*=` handlers.
- `style-src 'unsafe-inline'`: the same grep finds `style="--term-accent: ..."`,
  `style="--tile-accent: ..."`, `style="width: ...%"` attributes in the theme
  and dashboard templates, and `aabenintra_theme_preprocess_html()` writes an
  inline `--accent` on `<html>`. CKEditor 5 and core dialogs also set style
  attributes at runtime. Style *attributes* cannot be nonced (only `<style>`
  elements can), so dropping `'unsafe-inline'` would need those templates to be
  rewritten to data-attributes + CSS. The toggle `csp.style_unsafe_inline`
  exists for the day that happens.
- `img-src`/`media-src` `data: blob:`: CKEditor image previews, uploaded-file
  previews and generated avatars. `font-src data:` for inlined icon fonts.
- `worker-src blob:` for Vite-built islands that may spawn workers.
- `frame-ancestors 'self'` and `form-action 'self'`: nothing embeds the intranet
  and no form posts to a third party out of the box. SSO integrations change
  both, see below.
- `object-src 'none'`, `base-uri 'self'`: standard hardening with no known
  consumer in the product.
- `upgrade-insecure-requests`: harmless on an all-HTTPS tenant, rescues any
  hard-coded `http://` asset in editor content.

## Rollout procedure

1. Deploy. The module ships with `csp.report_only: true`, so the browser logs
   violations but blocks nothing. HSTS, Referrer-Policy and Permissions-Policy
   are enforced from the start (they do not break page rendering).
2. Optionally set *Violation report endpoint* to a collector you run (for
   example a report-uri.com style service or a small internal endpoint). Both
   `report-uri` and `report-to` are emitted. Without an endpoint, open the
   browser devtools console on the pages editors use most (dashboard, a story
   with embedded media, the CKEditor node form, the directory, a group page)
   and look for `[Report Only]` CSP messages.
3. For every violation decide: add the origin under *Extra sources per
   directive*, or fix the markup. Ignore violations from browser extensions
   (they typically report `chrome-extension://` or `moz-extension://` origins).
4. When a full week of normal use produces no actionable reports, untick
   *Report only* and save. The header becomes `Content-Security-Policy` and the
   browser enforces it. Keep the report endpoint configured; enforcement
   reports are how you learn about a new integration before users do.
5. HSTS preload: only tick *Add preload* when every subdomain of the tenant's
   domain serves HTTPS and you intend to submit the domain at hstspreload.org.
   Preload is close to irreversible, so it is off by default.

Rollback: untick *Emit security headers* (master switch) or set
`$config['aabenintra_security.settings']['csp']['report_only'] = TRUE;` in
`settings.php` and clear caches.

## Extending the allowlist for integrations

Extra sources are appended per directive, either in the form (one per line) or
as config overrides in `settings.php`, which win over the database and survive
a `drush config:import` or a re-install:

```php
// SSO identity provider (OIDC/SAML) used from the login page or in a popup:
$config['aabenintra_security.settings']['csp']['extra']['form_action'][] = 'https://login.kommune.dk';
$config['aabenintra_security.settings']['csp']['extra']['connect_src'][] = 'https://login.kommune.dk';
// IdP rendered in an iframe (for example a silent-refresh frame):
$config['aabenintra_security.settings']['csp']['extra']['frame_src'][] = 'https://login.kommune.dk';

// Microsoft 365 embeds (calendar, document previews) in an iframe:
$config['aabenintra_security.settings']['csp']['extra']['frame_src'][] = 'https://*.sharepoint.com';
$config['aabenintra_security.settings']['csp']['extra']['frame_src'][] = 'https://outlook.office.com';
$config['aabenintra_security.settings']['csp']['extra']['img_src'][] = 'https://*.sharepoint.com';

// The intranet itself embedded in another portal (both directions needed:
// CSP frame-ancestors here, and the other portal's frame-src):
$config['aabenintra_security.settings']['csp']['extra']['frame_ancestors'][] = 'https://portal.kommune.dk';

// Video embeds allowed in editor content:
$config['aabenintra_security.settings']['csp']['extra']['frame_src'][] = 'https://www.youtube-nocookie.com';
$config['aabenintra_security.settings']['csp']['extra']['frame_src'][] = 'https://player.vimeo.com';

// WebSocket chat or live notifications on another host:
$config['aabenintra_security.settings']['csp']['extra']['connect_src'][] = 'wss://chat.kommune.dk';
```

Rules:

- A source expression is one token: a scheme-host (`https://host`, wildcards
  like `https://*.example.dk`), a scheme (`data:`, `blob:`, `wss:`) or a
  keyword in quotes (`'unsafe-eval'`). Anything containing whitespace, `;` or
  `,` is dropped silently; the form rejects it with an error.
- `'none'` disappears from a directive as soon as you add a real source to it.
- Unknown directive keys are ignored. Available keys: `default_src`,
  `script_src`, `style_src`, `img_src`, `font_src`, `connect_src`,
  `media_src`, `frame_src`, `frame_ancestors`, `worker_src`, `manifest_src`,
  `base_uri`, `form_action`, `object_src`.
- Note that `X-Frame-Options: SAMEORIGIN` (set by core) stays even when you
  add `frame_ancestors`. Current browsers prefer `frame-ancestors` and ignore
  `X-Frame-Options` when both are present; only legacy browsers would still
  refuse the embed.

Keep the allowlist as narrow as possible; `https:` alone or `*` makes the
policy decorative.

### Inline scripts and the nonce (off by default)

Core has no CSP nonce support. The module provides a minimal one:

- `csp.use_nonce: true` adds `'nonce-<random>'` to `script-src`.
- Twig: `<script nonce="{{ csp_nonce() }}">...</script>`.

Only turn it on if a template actually needs an inline script. The design
choice around caching:

- `csp_nonce()` returns a render array with `max-age: 0`, so any template that
  prints it bubbles "uncacheable" into the render cache, the dynamic page cache
  and the page cache. A cached body with a stale nonce would otherwise no
  longer match the fresh header.
- The response subscriber puts the nonce in the header when a template consumed
  it (and then also trips the page-cache kill switch), or when the response can
  never be stored in the anonymous page cache (authenticated users, POST,
  sessions). For an anonymous, page-cacheable response that did not use the
  nonce, none is emitted: a nonce baked into a shared cached header could be
  read and reused by an attacker and would give no protection.
- Headers are added at `KernelEvents::RESPONSE` priority -10, after the dynamic
  page cache has stored its copy (priority 7), so the nonce is never persisted
  there.

`'strict-dynamic'` and hash-based allowlists were considered and rejected for
now: the product has no inline scripts to protect, and both make third-party
integrations harder to reason about for operators.

## Verifying

```bash
curl -sI https://aabenintra.fenixnordic.solutions/ | grep -iE \
  'strict-transport|content-security|x-frame|x-content-type|referrer-policy|permissions-policy|reporting-endpoints'
```

Expect one of `content-security-policy-report-only` (rollout) or
`content-security-policy` (enforced), plus the other five. Then run an external
scanner against the tenant hostname: securityheaders.com and the Mozilla HTTP
Observatory (developer.mozilla.org/en-US/observatory). Both will flag
`'unsafe-inline'` in `style-src` (see above) and report-only mode; everything
else should pass. Re-check after each integration you add.

Automated tests: `tests/src/Unit` (policy builder and the subscriber against the
shipped defaults, no database needed) and `tests/src/Kernel` (real event
dispatch through the installed module, config schema and defaults, needs the CI
database).

## Known limitations

- `style-src 'unsafe-inline'` is needed by the theme templates and CKEditor 5
  (style attributes). Removing it requires template work; the toggle is there.
- The module sets headers from PHP. Responses that never reach Drupal
  (Traefik errors, static files served directly by Apache from `web/`) do not
  get them. Apache serves `web/sites/default/files/*` and theme assets
  directly; those are not HTML, so the practical impact is the missing HSTS on
  asset responses. If that matters, add the same headers at the Traefik
  middleware level as well; the module skips any header that is already set.
- Report-only mode produces no server-side log unless a report endpoint is
  configured; the browser console is the only signal otherwise.
- `report-to` requires the `Reporting-Endpoints` header, which the module emits
  only when a report endpoint is set. Browsers without Reporting API support
  fall back to `report-uri`.
- `Permissions-Policy` is a single string: the form validates characters, not
  feature names. Unknown features are ignored by browsers.
- The nonce feature is intentionally minimal (see above); it does not cover
  `style-src` nonces and is not wired into any core inline script, because
  core has none to wire.
