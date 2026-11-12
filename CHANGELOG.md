# Changelog

All notable changes to AabenIntra are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow
[Semantic Versioning](https://semver.org/).

<!--
How to maintain this file

- Every PR that changes behaviour adds a line under [Unreleased] in one of the
  sections below. Dependabot bumps are summarised per release, not listed.
- On release: rename [Unreleased] to [vX.Y.Z] - YYYY-MM-DD, add a fresh
  [Unreleased] on top, tag `vX.Y.Z` and push the tag. The Release workflow
  (.github/workflows/release.yml) publishes a GitHub release whose notes are
  generated from PR titles grouped by label (.github/release.yml); this file
  stays the curated, operator-facing record.
- Sections (omit empty ones):

  ### Features
  ### Fixes
  ### Breaking
  ### Security
  ### Upgrade notes   <- anything an operator must DO when deploying: run a
                        drush command, add an .env variable, rebuild the image,
                        expected downtime, how to roll back.

- One line per change, imperative, factual, with the PR number. No marketing.
- PR labels drive the generated notes: security, feature/enhancement, bug,
  a11y, compliance, tech-debt, cross-cutting, documentation, dependencies.
-->

## [Unreleased]

### Features
- `aabenintra_ops` module: pluggable health checks (`HealthCheckInterface`,
  tagged `aabenintra_ops.check`), `drush aabenintra:validate` (post-install and
  post-deploy tenant validation, `--strict`, `--format=json`, exit 1 on failure)
  and `drush aabenintra:verify-isolation` (tenant isolation contract).
- `.github/release.yml` and a tag-triggered Release workflow that publishes
  GitHub release notes generated from PR titles.

### Upgrade notes
- `scripts/deploy_post.sh` now enables `aabenintra_ops` and runs
  `drush aabenintra:validate` after `updb`; a failing check fails the
  post-deploy. `scripts/install_prod.sh` runs it as its final step.
- New docs: `docs/DEPLOYMENT.md` (runbook, rollback), `docs/TENANT-ISOLATION.md`
  (isolation model and how to verify it), this changelog.

## History

Condensed from merged pull requests and the pre-PR commit history. Dependency
bumps are summarised.

### 2026-11
- Docs: author line in README (#170). Dependencies: drupal/token 1.18 (#169).

### 2026-10
- Security: Drupal core 11.4.8 -> 11.4.10 plus contrib security updates for
  SA-CORE-2026-014/015/016 (#162); core 11.4.11 (#165-#168).
- CI: the deploy dispatch runs the Drush post-deploy (DB snapshot + updb + cr)
  on every push to main; it was previously off by default, so the 11.4.10
  security deploy ran without updb or a rollback snapshot (#163).
- Dependencies: vite 8.3.4/8.3.5, focal_point 2.1.3, core 11.4.8.

### 2026-09
- Security: Drupal core 11.4.7 and entity 1.8.0 (#149).
- CI: Dependabot version updates for composer, docker, docker-compose, npm and
  GitHub Actions (#130); build-check workflow builds the production image on
  every pull request (#143); allow the symfony/runtime composer plugin so the
  image builds (#133).
- Dependencies: Drupal core 11.3.11 -> 11.4.7, MariaDB 11 -> 13 (#139), vite 6
  -> 8, drush 13.8, docker build actions v7/v4.

### 2026-06
- Fresh-default theming: cool sky palette, per-term colours, menu/nav structure,
  branded taxonomy term landing (#111).
- Production deploy scaffolding: Dockerfile (drupal:11-php8.4-apache + redis
  extension, 512M memory limit), docker-compose (drupal + MariaDB + Redis,
  Traefik host routing), env-driven settings.php, deploy dispatch workflow,
  `scripts/install_prod.sh` bootstrap and `scripts/deploy_post.sh`.
  First tenant live at aabenintra.fenixnordic.solutions on 2026-06-19.
- Product build-out (pre-PR commits, 2026-06-14): Editorial theme with per-user
  customisation, EN/DA bilingual recipes, workspaces (group), Document and Tip
  sharing, organisational structure model (org/location taxonomy + groups),
  mandatory reading with acknowledgement receipts and compliance report,
  employee directory and org chart, expert finder, comments and reactions,
  kudos wall, activity feed, modular dashboard tile grid, followable channels
  and targeted news.
