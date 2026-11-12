# AabenIntra

A Drupal 11 intranet for Danish organisations - kommuner and companies - usable
as a hosted tenant or self-hosted on the organisation's own server. Themed
Drupal (custom front-end theme + Gin admin) with progressive-enhancement
"islands" for the personalised dashboard, channels, reactions and mandatory
reading; authoring, preview, permissions and translation stay 100% native.
Bilingual EN/DA out of the box.

The product is packaged as composable **Drupal recipes** plus custom modules,
applied imperatively - no config sync. A fresh tenant is `composer install` ->
bring up the containers -> run `scripts/install_prod.sh`.

## Local development (DDEV)

```bash
ddev start
ddev composer install
ddev drush site:install minimal --account-name=admin --account-pass=admin -y
ddev drush recipe ../recipes/aabenintra_base
ddev drush recipe ../recipes/aabenintra_media
ddev drush config:delete core.entity_form_display.user.user.default -y
ddev drush config:delete core.entity_view_display.user.user.default -y
ddev drush recipe ../recipes/aabenintra_content
# i18n, groups, feature modules and theme: see CONTRIBUTING.md
```

The `drush recipe` path is relative to the Drupal web root, so recipes in
`web/recipes/<name>` are referenced as `../recipes/<name>`. The full order,
including the i18n and groups steps and why the two `config:delete` lines are
needed, is in [CONTRIBUTING.md](CONTRIBUTING.md) and
[docs/RECIPES.md](docs/RECIPES.md).

## Demo content (dev / staging only)

`aabenintra_demo` seeds realistic intranet demo content. **Never enable it in
production.**

```bash
ddev drush en aabenintra_demo -y
ddev drush aabenintra:demo-seed     # departments, topics, users, media, stories
ddev drush aabenintra:demo-clear    # remove it all again
```

## Recipes

| Recipe | Purpose |
| --- | --- |
| `aabenintra_base` | Core Standard, Gin admin, admin toolbar, pathauto/token, Layout Builder |
| `aabenintra_media` | Image media type, focal point, responsive + tile S/M/L image styles |
| `aabenintra_content` | Story / Event / Document, department / topic / audience / organisation / location taxonomies, fields and displays |
| `aabenintra_i18n` | Danish language, URL-prefix negotiation, content translation settings, shipped Danish UI strings |
| `aabenintra_groups` | Workspace and Community groups (drupal/group) with Document + Tip sharing, members-only by default |

Planned: social (#24), search (#28), theme (#41) and the umbrella tenant
recipe (#42). See [docs/RECIPES.md](docs/RECIPES.md).

## Modules

`web/modules/custom/`, package `AabenIntra`:

| Module | What it adds |
| --- | --- |
| `aabenintra_theme` | Per-user accent / colour scheme / density / nav / tile layout preferences, term colours, cache contexts |
| `aabenintra_dashboard` | Personalised Story tile dashboard (`/dashboard`) |
| `aabenintra_channels` | Followable topic channels, `/channels`, targeted `/news` |
| `aabenintra_social` | Threaded comments and reactions on content |
| `aabenintra_kudos` | Peer recognition wall (`/kudos`) |
| `aabenintra_activity` | Org- and group-scoped activity feed (`/activity`) |
| `aabenintra_compliance` | Mandatory reading, acknowledgement receipts, compliance report + CSV |
| `aabenintra_directory` | Employee profiles, people directory, org chart, find-an-expert |
| `aabenintra_groups` | Group types, content plugins and member roles built in code |
| `aabenintra_i18n` | Danish translations of the custom UI strings |
| `aabenintra_demo` | Demo content (never in production) |

## Documentation

Start with [ARCHITECTURE.md](ARCHITECTURE.md).

| Document | Covers |
| --- | --- |
| [CONTRIBUTING.md](CONTRIBUTING.md) | DDEV setup, PR flow, commit convention, labels, coding standards |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Recipe-first design, module map, request flow, theming, i18n, groups, tenant model, deploy, data stores |
| [SECURITY.md](SECURITY.md) | Reporting vulnerabilities, SLAs, supported versions, Drupal SA coordination |
| [CHANGELOG.md](CHANGELOG.md) | Release notes |
| [docs/RECIPES.md](docs/RECIPES.md) | Recipe reference, apply order, dependency graph, export workflow |
| [docs/ISLANDS.md](docs/ISLANDS.md) | How to add a progressive-enhancement island |
| [docs/API.md](docs/API.md), [docs/openapi.yaml](docs/openapi.yaml) | Endpoints today and the API versioning policy |
| [docs/PWA-OFFLINE.md](docs/PWA-OFFLINE.md) | Offline scope and sync conflict design (M12) |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | Deployment runbook and rollback |
| [docs/BACKUPS.md](docs/BACKUPS.md) | Per-tenant backups and restore tests |
| [docs/TENANT-ISOLATION.md](docs/TENANT-ISOLATION.md) | Multi-tenant data isolation model |
| [docs/SECURITY-HEADERS.md](docs/SECURITY-HEADERS.md) | CSP, HSTS and related headers |
| [docs/TRAEFIK-HARDENING.md](docs/TRAEFIK-HARDENING.md) | Traefik rate limiting and WAF guidance for operators |
| [docs/GDPR.md](docs/GDPR.md) | Data export and right-to-erasure tooling |
| [docs/AUDIT-LOG.md](docs/AUDIT-LOG.md), [docs/SESSION-POLICY.md](docs/SESSION-POLICY.md) | Authentication audit log, session and device policy |
| [docs/QUALITY.md](docs/QUALITY.md) | phpstan, coding standards, tests and the CI gate |
| [docs/OBSERVABILITY.md](docs/OBSERVABILITY.md) | Error tracking, structured logging, Grafana dashboard |
| [docs/ACCESSIBILITY.md](docs/ACCESSIBILITY.md) | WCAG 2.2 AA / EN 301 549 statement and CI gate |

## License

GPL-2.0-or-later (required for Drupal code).

## Author

Mads Nørgaard, senior developer and DevOps engineer (Drupal, Nuxt). [madsnorgaard.net](https://madsnorgaard.net)
