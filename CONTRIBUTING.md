# Contributing to AabenIntra

AabenIntra is production software for Danish organisations in the public
sector. Changes merged to `main` deploy to the reference tenant automatically.
Read this once before your first PR; it is short on purpose.

## Before you start

- Read [ARCHITECTURE.md](ARCHITECTURE.md) for the recipe-first model and the
  module map, and [docs/RECIPES.md](docs/RECIPES.md) before touching anything
  under `web/recipes/`.
- Every change starts as a GitHub issue (or references one). Labels, epics
  and milestones below tell you where it belongs.
- Security problems are **not** reported as issues. See [SECURITY.md](SECURITY.md).

## Local setup (DDEV)

Requirements: Docker, [DDEV](https://ddev.com), git. `.ddev/config.yaml`
pins PHP 8.3, MariaDB 10.11, nginx-fpm and Node 22 (with corepack).

```bash
git clone https://github.com/madsnorgaard/AabenIntra.git
cd AabenIntra
ddev start
ddev composer install
ddev drush site:install minimal --account-name=admin --account-pass=admin -y
```

Then apply the product in the same order `scripts/install_prod.sh` uses. The
`drush recipe` path is relative to the web root, so recipes in
`web/recipes/<name>` are `../recipes/<name>` (or the absolute container path
`/var/www/html/web/recipes/<name>`).

```bash
ddev drush recipe ../recipes/aabenintra_base
ddev drush recipe ../recipes/aabenintra_media

# The content recipe owns the user form/view displays (org unit + location
# fields). Standard already created differing ones and recipes refuse to
# overwrite differing config, so drop them first.
ddev drush config:delete core.entity_form_display.user.user.default -y
ddev drush config:delete core.entity_view_display.user.user.default -y
ddev drush recipe ../recipes/aabenintra_content

# i18n: enable the modules via drush first (a recipe-time install of
# `language` leaves URL negotiation inactive), then the recipe.
ddev drush en language locale content_translation config_translation -y
ddev drush language:add da
ddev drush recipe ../recipes/aabenintra_i18n
ddev drush locale:update

# Groups: modules via drush, then finalise the relationship plugins.
ddev drush en group gnode aabenintra_groups -y
ddev drush aabenintra_groups:install-plugins
ddev drush role:perm:add administrator 'administer group'
ddev drush role:perm:add administrator 'access group overview'

# Feature modules, theme, front page.
ddev drush en aabenintra_theme aabenintra_dashboard aabenintra_compliance \
  aabenintra_directory aabenintra_social aabenintra_kudos \
  aabenintra_channels aabenintra_activity -y
ddev drush theme:enable aabenintra
ddev drush config:set system.theme default aabenintra -y
ddev drush config:set system.site page.front /dashboard -y
ddev drush cr
```

If the order above and `scripts/install_prod.sh` ever disagree, the script
wins; update this file.

### Demo content (local and staging only)

```bash
ddev drush en aabenintra_demo -y
ddev drush aabenintra:demo-seed
ddev drush scr scripts/seed_structure.php
ddev drush scr scripts/seed_profiles.php
ddev drush scr scripts/seed_design_demo.php
ddev drush scr scripts/seed_channels.php
ddev drush aabenintra_activity:backfill
```

`aabenintra_demo` and the `scripts/seed_*.php` scripts are never run on a
customer tenant. The install script only seeds when the node table is empty,
and that path exists for the reference/demo tenant. A PR that adds demo data
must keep it inside `aabenintra_demo` or `scripts/seed_*.php`, guarded, and
removable with `drush aabenintra:demo-clear`.

### Theme build

```bash
cd web/themes/custom/aabenintra
npm install        # not `npm ci`; see the lockfile note below
npm run build      # or: npm run watch
```

`dist/` is committed and is what production serves - the Docker image runs no
Node. Rebuild and commit `dist/` in the same PR as any change under `src/`.

## Branches, pull requests, deploys

- Branch from `main`: `feat/...`, `fix/...`, `docs/...`, `ci/...`, `ops/...`,
  `security/...`, `chore/...`.
- Open a PR against `main`. The `Build check` workflow builds the production
  image; a red build blocks the merge. Describe what changed, why, and how you
  verified it (locally, in CI, or not at all - say so).
- Reference issues with `Closes #N` so they close on merge.
- Keep PRs small and single-purpose. Stacked PRs are fine; merge the base
  first.
- **Merging to `main` deploys to production.** `.github/workflows/deploy.yml`
  dispatches to the infrastructure repository, which pulls, rebuilds the
  image, restarts the stack and runs `drush cr && drush updb` inside the
  container (`scripts/deploy_post.sh`). There is no staging hop. If a change
  needs a manual step on the server (a new `.env` key, a one-off drush
  command), write it in the PR body under "Operator steps" and coordinate the
  merge. The runbook and rollback are in `docs/DEPLOYMENT.md`.
- Do not push to `main` directly; do not merge your own PR without a green
  build check.

### Dependabot

Weekly PRs (Mondays) for composer, the theme's npm packages, the Docker base
image, docker-compose images and GitHub Actions. Handling:

- Patch and minor bumps of core and contrib: merge when the build check is
  green. A core security release is handled the same day; see
  [SECURITY.md](SECURITY.md).
- Major bumps of stateful images (`mariadb`, `redis`) and of Drupal core
  majors: never auto-merge. Open an issue, plan the migration.
- npm bumps under `web/themes/custom/aabenintra`: Dependabot may desync
  `package-lock.json`. Pull the branch, run `npm install && npm run build`,
  commit both the lockfile and `dist/`, then merge.
- Grouped composer updates can drop a lock entry; if `composer validate`
  complains, regenerate with `composer update --lock`.

## Commit messages

Imperative subject, `type(scope): subject` where the type helps:

```text
feat(channels): add followable topics and a targeted news feed
fix(groups): hide secret groups from non-members in the directory
docs: add deployment runbook
ci: run drush post-deploy on every push to main
security: Drupal core 11.4.8 => 11.4.10 + contrib security updates (SA-CORE-2026-014)
build: allow symfony/runtime composer plugin
chore(deps): bump vite to 8.3.5
```

Types in use: `feat`, `fix`, `docs`, `ci`, `build`, `security`, `ops`,
`chore`, `refactor`, `test`. The body explains *why* - what was wrong, what
the alternatives were - not what the diff shows. Wrap at 72 columns. Reference
issues in the body or footer (`Closes #65`). Do not mention tooling used to
write the change.

## Labels, epics and milestones

Issues carry one label from each applicable group:

| Group | Labels |
| --- | --- |
| Kind | `type:epic` (parent issue), otherwise one of `feature`, `security`, `tech-debt`, `a11y`, `compliance`, `cross-cutting`, `preservation`, `bug`, `documentation` |
| Epic | `epic:foundation`, `epic:content-media`, `epic:groups-social`, `epic:search`, `epic:dashboard`, `epic:tenant`, `epic:cicd`, `epic:marketing`, `epic:theme`, `epic:i18n`, `epic:comms`, `epic:people`, `epic:ai`, `epic:analytics`, `epic:integrations`, `epic:a11y`, `epic:mobile`, `epic:aabenforms`, `epic:gtm` |
| Priority | `priority:critical`, `priority:high`, `priority:medium`, `priority:low` |
| Flags | `risk` (needs verification or carries known risk), `good first issue`, `help wanted` |

Milestones are the delivery phases, `M0 Foundation` through `M14
Go-to-Market` (search is M3, tenant packaging M5, CI/CD M7, comms and
compliance M8, integrations and SSO M10, accessibility and mobile M12). An
`[EPIC]` issue per milestone links the children. A new issue goes under the
matching epic label and milestone; if none fits, it is probably
`cross-cutting` without a milestone. Dependabot adds `dependencies` plus an
ecosystem label on its own.

## Coding standards

- PHP 8.3+ features, `declare(strict_types=1);` in every file, `final`
  classes, constructor property promotion with `readonly`, typed properties
  and return types everywhere. Drush commands use PHP attributes
  (`#[CLI\Command(...)]`), see
  `web/modules/custom/aabenintra_activity/src/Drush/Commands/ActivityCommands.php`.
- Drupal coding standards (`Drupal` and `DrupalPractice` rulesets via
  `drupal/coder`, installed with `drupal/core-dev`). Two-space indentation,
  LF, trailing newline (`.editorconfig`).
- Static analysis with phpstan (`mglaman/phpstan-drupal`). The level, the
  config file and the CI gate are defined in `docs/QUALITY.md` and
  `.github/workflows/ci.yml`; run the same commands locally before pushing.
  Until that workflow exists on your branch:

  ```bash
  vendor/bin/phpcs --standard=Drupal,DrupalPractice web/modules/custom web/themes/custom/aabenintra
  vendor/bin/phpstan analyse --level 6 web/modules/custom
  ```

- Tests: PHPUnit unit tests under `tests/src/Unit` need no database and run
  anywhere; kernel and functional tests run in CI. New services get a unit
  or kernel test; new routes get a functional test covering access and the
  CSRF path.
- Security defaults: every mutating route is `methods: [POST]` or `PATCH`
  with an access requirement and a CSRF check; entity ids are upcast with a
  `\d+` requirement; no raw SQL string interpolation (use the query builder
  with placeholders); output through Twig autoescaping or `#markup` with
  `Xss::filter` where unavoidable; all user-controlled values validated
  against an allow-list (see `PreferencesService::sanitize()`).
- Cacheability: render arrays declare contexts and tags. Prefer the hashed
  contexts `aabenintra_user_prefs` / `aabenintra_user_groups` to a raw
  `user` context when they are sufficient. Writes invalidate the tags every
  reader declares.
- Accessibility: WCAG 2.2 AA / EN 301 549 is a requirement, not a polish
  step - real buttons, `aria-pressed`, `aria-live`, keyboard paths, visible
  focus. See `docs/ACCESSIBILITY.md` and [docs/ISLANDS.md](docs/ISLANDS.md).
- Writing: English in code and docs, Danish in the `.po`. Hyphens, not em
  dashes. No marketing language. Never name competitor products in anything
  committed.

## Adding a module

1. `web/modules/custom/aabenintra_<name>/aabenintra_<name>.info.yml` with
   `package: 'AabenIntra'`, `core_version_requirement: ^11`, `php: ^8.3`
   and explicit `dependencies:` (`drupal:node`, `group:group`,
   `aabenintra:aabenintra_social`, ...).
2. Schema in `hook_schema()`; fields, bundles and displays in
   `hook_install()`, written idempotently (`loadByName()` checks) so the hook
   is safe to re-run. Changes for existing tenants go in `hook_update_N()`.
3. Services in `*.services.yml` with constructor injection; routes in
   `*.routing.yml`; a `templates/` directory and `hook_theme()` if it renders
   pages.
4. Add the module to the `drush en` block in `scripts/install_prod.sh` (one
   line, in dependency order) until the umbrella recipe (#42) exists.
5. Danish strings in `aabenintra_i18n/translations/aabenintra.da.po`.
6. Document it: one row in the module map in
   [ARCHITECTURE.md](ARCHITECTURE.md), endpoints in `docs/openapi.yaml`.

## Adding or changing a recipe

Read [docs/RECIPES.md](docs/RECIPES.md). In short: build the config on a
local site, add the config names to the relevant `scripts/export_*.php` map,
run it, check that no `uuid:` or `_core:` landed in the YAML, and verify by
applying the whole chain on a fresh `site:install minimal`. Recipes never
overwrite differing config; if you need to replace something an earlier
recipe created, see the `config:delete` pattern in the install script.

## Translations

- All UI strings go through `t()` / `$this->t()` / `|t` and get a Danish
  entry in `web/modules/custom/aabenintra_i18n/translations/aabenintra.da.po`
  (`msgid` exact, including placeholders such as `@name`).
- The file is imported on install as *customised* translations. On an
  existing site, re-import after changes with
  `drush locale:import da web/modules/custom/aabenintra_i18n/translations/aabenintra.da.po --type=customized --override=all`.
- Config labels (field and content type names) are a separate track (#60).

## Reporting security issues

Do not open a public issue. Follow [SECURITY.md](SECURITY.md).
