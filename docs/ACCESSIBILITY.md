# Accessibility and performance gates

AabenIntra is sold to Danish municipalities, which are bound by *Lov om
tilgængelighed af offentlige organers websteder og mobilapplikationer* and the
harmonised standard EN 301 549. The product target is **WCAG 2.2 level AA** for
every page a regular employee meets. This document describes the automated
gates that protect that target, how to run them locally, how to triage a
failure, the manual checks automation cannot do, and the Lighthouse ratchet.

Related: [ACCESSIBILITY-KNOWN-ISSUES.md](ACCESSIBILITY-KNOWN-ISSUES.md) (accepted
exclusions), [ACCESSIBILITY-STATEMENT.da.md](ACCESSIBILITY-STATEMENT.da.md) and
[ACCESSIBILITY-STATEMENT.en.md](ACCESSIBILITY-STATEMENT.en.md) (the statement
each tenant publishes). Tracking issues: #99 (epic item), #118 (this gate and
the statement), #123 (Lighthouse budgets), #100 (keyboard and focus work for
the islands).

## What runs, and when

Workflow: `.github/workflows/a11y-perf.yml`, job **axe + Lighthouse**.

Triggers: pull requests touching `web/themes/**`, `web/modules/**`,
`web/recipes/**`, `tests/e2e/**`, the install/seed scripts or `composer.lock`;
`workflow_dispatch`; and a weekly schedule (Monday 05:00 UTC) so contrib or
core updates that merge without touching those paths still get measured.

The job does, in order:

1. PHP 8.4 (`shivammathur/setup-php`) with `pdo_sqlite`, `gd`, `intl`, etc.,
   and `composer install --no-dev`.
2. `scripts/install_ci.sh` - a disposable install that mirrors
   `scripts/install_prod.sh` step for step (recipe order, the two
   `config:delete` workarounds, language and group modules enabled via drush,
   theme re-enabled, activity backfill, demo seed) against an SQLite database.
   SQLite is viable because no custom module uses database-specific SQL; if that
   changes, set `AI_CI_DB_URL` to a MariaDB service container instead. The
   script also sets a known password on the demo user `demo.anna` for the
   authenticated checks (she has an org unit and follows channels, so the
   dashboard cascade is exercised).
3. `php -S 127.0.0.1:8080 -t web web/.ht.router.php` with
   `PHP_CLI_SERVER_WORKERS=4`.
4. Playwright (`tests/e2e`): the global setup logs `demo.anna` in through the
   normal login form, stores the session for the authenticated project and
   writes the session cookie to `tests/e2e/.auth/cookie.txt` for Lighthouse.
   Then the axe specs run (see below).
5. Lighthouse CI (`tests/e2e/lighthouse`): an anonymous collect (`/user/login`)
   and an authenticated collect (`/dashboard`, `/news`) with the cookie as an
   extra request header. Three runs per URL, assertions on the median.
6. Artifacts, always uploaded: `axe-playwright` (axe JSON per page, Playwright
   HTML report, traces and screenshots of failures) and `lighthouse` (HTML and
   JSON reports per run plus the assertion results).

Timeout 30 minutes, one run per branch at a time (`concurrency`).

### The axe gate

`tests/e2e/lib/axe.ts` runs axe-core with the tags `wcag2a`, `wcag2aa`,
`wcag21a`, `wcag21aa`, `wcag22aa` - together that is WCAG 2.2 AA (axe tags are
cumulative per version). Violations with impact **serious** or **critical**
fail the test. **moderate** and **minor** violations are attached to the test
as an advisory text file and printed as a warning; they do not fail the build
but should be read when touching the affected template.

Pages covered:

| Project | Page | Spec |
|---|---|---|
| anonymous | `/user/login` | `specs/anonymous.spec.ts` |
| authenticated | `/dashboard`, `/news`, `/directory`, `/channels`, `/kudos`, `/activity` | `specs/authenticated.spec.ts` |
| authenticated | first story node (found via `/news`), first channel page (found via `/channels`) | `specs/authenticated.spec.ts` |
| authenticated, Danish | `/da/dashboard`, `/da/news` with `<html lang="da">` asserted | `specs/danish.spec.ts` |

Not yet covered, and worth adding when they ship or stabilise: the search
result page (search island), group landing pages, `/experts`, `/org-chart`,
the node edit forms (Gin admin theme, mostly core's responsibility), and the
Personalise and navigation drawers in their *open* state (#100).

### The Lighthouse gate

`tests/e2e/lighthouse/common.cjs` holds the budgets. Two tables live there:
`TARGETS` (the numbers from #123) and `THRESHOLDS` (what is enforced today).

| Metric | Target (#123) | Enforced today | Aggregation |
|---|---|---|---|
| Performance | >= 0.85 | 0.85 | median of 3 |
| Accessibility | >= 0.95 | 0.95 | median of 3 |
| Best practices | >= 0.90 | 0.90 | median of 3 |
| Largest Contentful Paint | < 2500 ms | 2500 ms | median of 3 |
| Cumulative Layout Shift | < 0.1 | 0.1 | median of 3 |

Settings: desktop preset, simulated throttling, categories limited to the three
above, and the HTTPS/HTTP2 audits skipped because the CI origin is plain HTTP
on localhost (production sits behind Traefik with TLS and HTTP/2).

#### Ratchet plan

Numbers measured on a shared GitHub runner against PHP's built-in server are
noisy. The rules:

- A threshold is **never lowered** without a dated note in this section
  explaining why, and the target in #123 stays the goal.
- When a metric clears its threshold with margin on three consecutive weekly
  runs, raise the threshold to within 0.02 (score) or 10 % (ms) of the measured
  median, up to the target.
- If a metric flaps, first fix the cause of variance (fonts, large images,
  render-blocking CSS) rather than widening the budget. If the variance is
  genuinely the runner, increase `numberOfRuns` to 5 before anything else.
- The mobile profile (Lighthouse default) is not asserted yet. Add it as a
  second collect when the PWA/frontline work (#101, #102) lands, with its own
  thresholds.

Notes log:

- 2026-11-08: initial thresholds set equal to the #123 targets; adjust after
  the first weeks of measurements if they prove unstable.

## Running locally

Requirements: PHP 8.4 (or 8.3) CLI with `pdo_sqlite`, `gd`, `intl`, `mbstring`,
`xml`, `zip`; Composer; Node 24; a Chromium (Playwright installs its own,
Lighthouse uses the system Chrome or `CHROME_PATH`).

```bash
composer install
scripts/install_ci.sh                       # ~3-5 min; writes web/sites/default/settings.php
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8080 -t web web/.ht.router.php &

cd tests/e2e
npm ci
npx playwright install chromium
npx playwright test                         # axe gate; add --headed or --ui to watch
npx playwright show-report                  # HTML report with the attached axe JSON
npm run lhci                                # Lighthouse, anonymous then authenticated
```

Useful variants:

- `npx playwright test specs/danish.spec.ts` - one spec.
- `AI_BASE_URL=https://staging.example/ npx playwright test` - point the suite
  at another site (the login still has to work with `AI_CI_USER_NAME` /
  `AI_CI_USER_PASS`).
- `AI_CI_DB_URL=mysql://user:pass@127.0.0.1:3306/aabenintra scripts/install_ci.sh`
  - install against MariaDB/MySQL instead of SQLite.
- DDEV users: `ddev exec scripts/install_ci.sh` is **not** what you want on a
  site you care about - the script deletes `settings.php` and installs a fresh
  site. Run the Playwright suite against your DDEV URL with `AI_BASE_URL`
  instead.

`install_ci.sh` is destructive by design (it removes `web/sites/default/
settings.php` unless `AI_CI_KEEP_SETTINGS=1`) and uses known passwords. Never
run it on a real tenant; that is what `install_prod.sh` is for.

## Triaging a failure

### axe

1. Open the failed test in the Actions log or download the `axe-playwright`
   artifact and open `playwright-report/index.html`. The assertion message
   lists each violation as `[impact] rule-id: help`, the axe help URL, and up
   to five CSS selectors of offending nodes with axe's own failure summary.
2. Reproduce locally (above) or with the attached trace
   (`npx playwright show-trace test-results/<...>/trace.zip`).
3. Decide:
   - **Fix it** (the default). Most findings are template or token changes:
     missing `alt`, a control without an accessible name, a label not
     associated with its input, insufficient contrast, a target under
     24x24 px, duplicate ids, missing `lang`. Theme source lives in
     `web/themes/custom/aabenintra/src` (rebuild `dist/` with `npm run build`
     in the theme directory and commit it) and module templates in
     `web/modules/custom/*/templates`.
   - **Not ours to fix right now** (core or contrib markup, or a fix that needs
     design work). Add a row to `docs/ACCESSIBILITY-KNOWN-ISSUES.md` *and* an
     entry in `tests/e2e/lib/known-issues.ts` with the narrowest possible CSS
     selector, the reason and the tracking issue. The rule keeps running on
     the rest of the page. Never disable a rule globally, never lower the
     impact threshold, and never add an exclusion without the doc row.
4. If the failure is an **advisory** (moderate/minor) it did not fail the
   build; still fix it when you are in that template.

### Lighthouse

1. Download the `lighthouse` artifact. `assertion-results.json` names the
   failing assertion and the measured value; `*.report.html` is the usual
   Lighthouse report with the audit details.
2. Accessibility category failures overlap with axe (Lighthouse uses axe too)
   but Lighthouse also counts contrast on hover/focus states and some
   best-practice rules; treat them like axe findings.
3. Performance: check first whether the regression is in bytes (new font,
   unoptimised image style, a library attached on every page) or in the
   server (slow query on the dashboard - the PHP built-in server makes TTFB
   visible). Compare with the previous run in the artifact of a green run on
   `main` (Actions -> the scheduled runs).
4. Best practices: console errors (a 404 for a missing asset counts), missing
   `width`/`height` on images (CLS), deprecated APIs from contrib JS.
5. Only after the cause is understood, and only with a note in the ratchet
   log, adjust a threshold.

## Manual checks

Automation finds roughly a third of WCAG problems. Before a release, and after
larger UI changes, someone walks through this on the demo site:

**Keyboard**
- Tab from the address bar: the first stop is the "Skip to main content" link
  and it works.
- Every interactive control (menu button, language switch, Personalise, tile
  pin buttons, follow buttons, directory filters, pager) is reachable with
  Tab/Shift+Tab and operable with Enter/Space. Arrow keys where a widget
  implies them (segmented controls in the Personalise panel).
- Opening the navigation drawer or the Personalise panel moves focus into it,
  Escape closes it and returns focus to the button that opened it, and Tab
  does not escape behind it while open (#100).
- No keyboard trap anywhere, including the media library dialogs in the
  editor.

**Focus visible**
- The focus ring (3 px accent outline) is visible on every control on light
  and dark scheme and with every accent swatch, including custom colours.
- Focus is never hidden behind the sticky header.

**Screen reader smoke test** (NVDA + Firefox or VoiceOver + Safari, 15 minutes)
- Landmarks: banner, navigation (named), main, contentinfo are announced;
  the drawers are announced with their names.
- Headings read in order on dashboard, news, a story: one h1, sections as h2.
- Tiles: title link, eyebrow, pinned state (`aria-pressed`) are read; the
  decorative image link is skipped.
- Forms: login, directory filters, comment form - each field has a name,
  errors are announced.
- Language: switching to Danish changes `lang` and the reader's voice.

**Zoom and reflow**
- 200 % browser zoom: no horizontal scroll at 1280 px, nothing clipped,
  text in tiles wraps rather than truncating.
- 400 % (or a 320 px wide viewport): single column, drawers become full
  screen, controls still usable.
- Text-only zoom / minimum font size 24 px in Firefox: layout survives.

**Reduced motion and colour**
- `prefers-reduced-motion: reduce`: drawer and tile transitions are removed
  (theme already respects it; confirm after CSS changes).
- Information is never conveyed by colour alone: channel colours have a dot
  *and* text, pinned has an icon with a label, mandatory-read has text.
- Dark scheme and every accent: body text, muted text, badges and buttons
  pass 4.5:1 (3:1 for large text and UI borders). Spot-check with the browser
  contrast picker; the tokens derive from `--accent` with relative colour so
  a custom accent is the risky case.

**Content**
- Images in stories have meaningful `alt` (editor responsibility; the axe
  gate only catches *missing* alt). Editoria11y for authors is part of #99 and
  not installed yet.
- Documents uploaded as PDF are a tenant responsibility; the statement says
  so.

Record the walk-through date and findings in `ACCESSIBILITY-KNOWN-ISSUES.md`
("Manual review log"), and in the statement's "preparation" section when a
tenant publishes it.

## Follow-ups

- Ship the accessibility statement as a `page` node in the content recipe
  (default content, EN and DA translations) so a tenant edits the placeholders
  in the UI and links it from the footer; today it is documentation only.
- Editoria11y for authors (#99).
- Keyboard and focus management for the islands (#100), then add the
  open-drawer states to the axe specs.
- Mobile Lighthouse profile alongside the PWA work (#101, #102).
