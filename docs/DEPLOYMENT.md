# Deployment runbook

How AabenIntra reaches production, how to check that it arrived, and how to
get back if it did not. Audience: whoever operates a tenant (the hosted
tenants on VPS2 today, a customer's own ops team for self-hosting).

Related documents: `CONTRIBUTING.md` (PR flow and labels), `ARCHITECTURE.md`
(recipe-first design, tenant model), `docs/TENANT-ISOLATION.md` (what a
tenant container does and does not share), `docs/BACKUPS.md` (scheduled
backups and restore tests), `CHANGELOG.md` (what each release changes and
what the operator must do), `SECURITY.md` (how security releases are
handled).

## 1. The moving parts

| Part | Where | Role |
| --- | --- | --- |
| `docker/Dockerfile` | repo | `drupal:11-php8.4-apache` + redis extension, `memory_limit=512M`, `composer install --no-dev`, copies custom modules/themes/recipes/scripts. The theme's committed `dist/` is the asset transport; no node build runs at deploy time. |
| `docker-compose.yml` | repo, checked out on the host | One stack per tenant: `drupal` (service name) + `aabenintra_db` (MariaDB 13) + `aabenintra_redis`. Traefik routes `Host(aabenintra.fenixnordic.solutions)` to port 80 of the Drupal container. Bind mounts: `./web/modules/custom`, `./web/themes/custom`, `./web/recipes`, `./scripts`, `./settings.php`, `./services.prod.yml`, `./data/private`, `./backups`; named volume `drupal_files` for public files. |
| `settings.php` | repo, bind-mounted | Everything secret comes from the environment (`DB_*`, `HASH_SALT`, `TRUSTED_HOSTS`, `REDIS_HOST`, `SMTP_*`). Private files at `/opt/drupal/private`, outside the docroot. Redis block is still commented out. |
| `.env` | host only, never committed | The deploy gate: the pipeline skips a project directory without one. `.env.example` is the contract. |
| `scripts/install_prod.sh` | repo | First install of a tenant (site:install + recipes + module enables + theme + validate). Run once, on an empty database. |
| `scripts/deploy_post.sh` | repo | Ongoing deploy post-step for operators running outside the hosted pipeline: `cr`, `updb`, `cr`, enable `aabenintra_ops`, `aabenintra:validate`. |
| `.github/workflows/deploy.yml` | repo | On push to `main` (or manual dispatch) sends a `repository_dispatch` to `madsnorgaard/contabo-infrastructure` with `project_type=drupal`, `skip_cim=true`, `run_updb=true` on pushes. |
| `contabo-infrastructure` `deploy.yml` | other repo, self-hosted runner | Does the actual work over SSH on the host (section 3). |
| `.github/workflows/build-check.yml` | repo | Builds the production image on every PR so a broken dependency bump is red before it merges and auto-deploys. |

AabenIntra is **not** a config-sync site. Schema and config arrive through
recipes and module `hook_install()` / `hook_update_N()`. `drush cim` must never
run against a tenant: with no `config/sync` directory it would delete active
config. That is what the `skip_cim` flag is for.

## 2. Pre-deploy checklist

1. CI is green on the PR: `Build check / image / drupal` (image builds) and the
   quality gates (phpstan, phpunit) once `ci.yml` is in place.
2. `CHANGELOG.md` has an entry under `[Unreleased]`. If the change needs an
   operator action (new `.env` variable, command to run, image rebuild) it is
   written under **Upgrade notes** before merge, not after.
3. Schema or data change? Confirm it ships as `hook_update_N()` /
   `hook_post_update_NAME()` in the owning module, because `drush updb` is the
   only thing that runs on deploy. A change made only in a recipe's `config/`
   directory does **not** reach existing tenants (recipes apply at install).
4. New module? Add it to the `drush en` block in `scripts/install_prod.sh`
   (fresh tenants) and to `expected_modules` in
   `web/modules/custom/aabenintra_ops/config/install/aabenintra_ops.settings.yml`
   (so `aabenintra:validate` notices when it is missing). Existing tenants need
   an `hook_update_N()` that installs it, or a documented manual `drush en`.
5. Database snapshot: the hosted pipeline takes one automatically before
   `updb` when `run_updb` is on (default for pushes). It refuses to run `updb`
   if the dump is under 50 KB. Snapshots live in
   `~/backups/<project_dir>/pre-deploy-<UTC timestamp>-<sha8>.sql.gz` on the
   host, the newest ten are kept. Outside the pipeline, take one yourself:
   `docker compose exec -T drupal drush sql:dump --extra-dump=--no-tablespaces | gzip -c > backups/pre-deploy-$(date -u +%Y%m%dT%H%M%SZ).sql.gz`.
6. Dependency PRs: Dependabot merges deploy exactly like feature merges. A
   Drupal core or contrib bump with pending updates is applied by `updb`; a
   base image bump rebuilds the container. Do not merge a dependency PR whose
   build check is red.

## 3. Deploy sequence (hosted tenants)

```
push to main
  -> AabenIntra deploy.yml: repository_dispatch {project_dir, project_type=drupal,
     drupal_service=drupal, repo_name=AabenIntra, run_updb=true, skip_cim=true}
  -> contabo-infrastructure deploy.yml on a self-hosted runner, over SSH to the host:
     1. gate: ~/docker/<project_dir>/.env must exist, else the deploy is skipped (green, no-op)
     2. git fetch origin main; checkout of docker-compose.yml, docker/, settings.php,
        composer.json/lock, web/modules/custom, web/themes/custom, scripts/, config/
        (bind-mounted paths update in place; the image is rebuilt from the same tree)
     3. docker compose pull; docker compose build drupal
     4. docker compose up -d --remove-orphans [--force-recreate]
     5. wait for the drupal container, then for `drush sql:query 'SELECT 1'` and `drush cr`
        (Redis may still be loading its dataset)
     6. if run_updb: drush sql:dump -> ~/backups/<project_dir>/pre-deploy-*.sql.gz (fail-closed)
     7. if run_updb: drush cr; drush updb -y; (skip_cim => no cim); drush cr
```

What the pipeline does **not** do today: run `scripts/deploy_post.sh` or
`drush aabenintra:validate`. Until the shared pipeline grows a post-deploy
hook (section 4.3), the validation is run by hand after each deploy, or by
`scripts/deploy_post.sh` for self-hosted operators.

`force_recreate` (manual dispatch input) recreates containers even when the
compose definition did not change; use it after `.env` edits, because
environment variables are only read at container creation.

## 4. Post-deploy verification

### 4.1 `drush aabenintra:validate`

Runs the `validate` group of `aabenintra_ops` checks and exits 1 if any check
fails (warnings do not fail unless `--strict`):

| Check | ok when | otherwise |
| --- | --- | --- |
| `modules` | every module in `expected_modules` is enabled | fail, lists missing |
| `demo_module` | `aabenintra_demo` is not enabled | warn (showcase tenant runs it on purpose) |
| `db_updates` / `post_updates` | no pending `hook_update_N` / post-update | fail: run `drush updb` |
| `theme_front_page` | default theme `aabenintra`, front page `/dashboard` | fail |
| `recipe_config` | node types, media type, image styles, vocabularies, user fields, `language.entity.da`, group types exist | fail: a recipe refused to apply |
| `redis` | Redis answers PING | skipped while `redis.connection` is unset in settings.php |
| `trusted_hosts` | exactly one valid pattern | fail on none/empty (TRUSTED_HOSTS unset), warn on several |
| `private_files` | path set, exists, writable, outside the docroot | fail |
| `public_files` | `public://` and `files/styles` writable | fail (root-owned styles dir after a bootstrap run as root) |
| `cron` | last run within 24 h | warn |
| `search_index` | every search_api index fully indexed | skipped without search_api; warn when behind |
| `mail` | configured mail plugin exists; smtp on with a host | fail when `system.mail` points at a missing plugin; warn on plain PHP mail() |
| `synthetic_pages` | `/dashboard` and `/directory` render HTTP 200 with their template marker | fail |

The synthetic requests run as uid 1 through an HttpKernel sub-request (no HTTP
round trip, no page cache involvement). Nothing is created or modified.

On the host:

```bash
cd ~/docker/aabenintra.fenixnordic.solutions
docker compose exec -T drupal drush aabenintra:validate
docker compose exec -T drupal drush aabenintra:validate --format=json --strict   # for scripts
docker compose exec -T drupal drush aabenintra:verify-isolation                  # see docs/TENANT-ISOLATION.md
```

Over SSH from the runner or a workstation (the host and port are deployment
secrets; the pattern is what matters):

```bash
ssh -p "$SSH_PORT" "$SSH_USER@$HOST" \
  "cd ~/docker/aabenintra.fenixnordic.solutions && docker compose exec -T drupal drush aabenintra:validate"
```

### 4.2 Smoke URLs and logs

- `https://aabenintra.fenixnordic.solutions/` serves the front page
  (`/dashboard`) with HTTP 200 for a logged-in user; what anonymous visitors
  see depends on the tenant's `access content` permission.
- `/da/` renders the Danish interface (URL-prefix negotiation is active).
- `/user/login` renders in the `aabenintra` theme; `/admin` in Gin.
- `/sites/default/files/styles/tile_medium/...` for any story image returns
  an image, not a 500 ("Error generating image." means `files/styles` is not
  writable by www-data).
- `docker compose logs --since 10m drupal` has no PHP fatals;
  `drush watchdog:show --severity=Error --count=20` is empty or explained.

### 4.3 Calling the validation from the shared pipeline

The shared `contabo-infrastructure` `deploy.yml` can run the check after its
"Run Drush post-deploy tasks" step without knowing anything about AabenIntra:
a generic `post_deploy_drush` client-payload field (default empty) executed as
`$DRUSH $POST_DEPLOY_DRUSH` with its exit code failing the job. AabenIntra's
`deploy.yml` would then send `"post_deploy_drush": "aabenintra:validate"`.
That change belongs to the other repository and needs its own PR; until it
lands, run the command by hand (4.1) after every deploy.

## 5. Multi-tenant sequence

Today there is one hosted tenant. The procedure is written for N so it does
not need rewriting when the second one arrives.

1. **Canary first.** Deploy to the showcase tenant (or the designated canary).
   Run `aabenintra:validate --strict` and the smoke URLs. Wait at least one
   cron cycle if the change touches cron, search or notifications.
2. **Then the rest, one at a time.** Each tenant is its own compose project
   directory with its own `.env`, database, Redis and snapshot directory. Each
   deploy is a separate dispatch (`project_dir` differs), so a failure stops at
   that tenant and never partially applies to the next one.
3. **Same image, same commit.** All tenants run the same `main` SHA. Do not
   leave a tenant on an older commit for convenience; `updb` ordering across
   releases is tested only forwards.
4. **Record.** Note the SHA, the snapshot filename and the validate result per
   tenant in the release's GitHub issue or the ops log.

## 6. Rollback

Decide first whether `updb` ran (it does on every push to `main`; check the
pipeline log for "Snapshot written:" and the `updb` output) and whether any
update hook actually did something (`drush updb` prints "No pending updates"
when nothing ran).

### 6.1 Code only (no update hook ran, or hooks were no-ops)

Either:

- `git revert <merge sha>` on `main` and push. The pipeline rebuilds the
  image from the reverted tree and recreates the container. This is the
  normal path and keeps history linear.
- Or on the host, re-tag the previous image and recreate:
  `docker image ls | grep aabenintra` shows the previous build (the compose
  project name prefixes the image). `docker tag <old image id> <current tag>`
  then `docker compose up -d --force-recreate drupal`. This buys minutes while
  the revert PR goes through; the next push overwrites it.

Then `drush cr` and `aabenintra:validate`.

### 6.2 Code and database (an update hook changed schema or data)

1. Put the previous code live (6.1). The container may now hold update hooks
   whose numbers are already recorded as applied; that is fine once the
   database is restored.
2. Restore the pre-deploy snapshot taken by the pipeline:
   ```bash
   cd ~/docker/aabenintra.fenixnordic.solutions
   ls -1t ~/backups/aabenintra.fenixnordic.solutions/pre-deploy-*.sql.gz | head -1
   gunzip -c ~/backups/aabenintra.fenixnordic.solutions/pre-deploy-<ts>-<sha8>.sql.gz \
     | docker compose exec -T drupal drush sql:cli
   docker compose exec -T drupal drush cr
   docker compose exec -T drupal drush aabenintra:validate
   ```
   The dump is a full `sql:dump` (all tables), so the restore replaces content
   created between the snapshot and the rollback. Say so to the customer.
3. Files are not in the snapshot. Uploads made after the snapshot survive the
   restore but their file entities do not; `drush entity:delete file` is not
   needed, orphan files are harmless until the next backup rotation.

### 6.3 Image or base dependency broke the container

Symptoms: container restarts, `Wait for Drupal container` loops, 502 from
Traefik. Revert the dependency PR (Dockerfile base tag, composer.lock) and
push; the build-check workflow would normally have caught this, so also check
why it did not.

## 7. First install of a tenant

1. Create the project directory on the host (`~/docker/<tenant host>/`), clone
   or let the pipeline check out the repo files, copy `.env.example` to `.env`
   and fill every value (`openssl rand -hex 32` for `HASH_SALT`; `TRUSTED_HOSTS`
   is a regex with escaped dots). DNS A record and Traefik labels for the
   tenant host (edit the `Host(...)` label in `docker-compose.yml` for a new
   tenant; a per-tenant compose override is the longer-term answer).
2. Trigger the deploy with `run_updb=false` (workflow_dispatch) so the
   pipeline builds and starts the containers without touching an empty
   database.
3. Run the bootstrap **detached** so an SSH drop cannot kill it half-way:
   ```bash
   docker compose exec -T -d drupal bash -c \
     'bash /opt/drupal/scripts/install_prod.sh > /tmp/bootstrap.log 2>&1; echo DONE_EXIT_$? >> /tmp/bootstrap.log'
   docker compose exec -T drupal tail -f /tmp/bootstrap.log
   ```
   The script ends with `drush aabenintra:validate` (failing it fails the
   script) and prints a one-time admin login link.
4. Fix file ownership if the bootstrap ran as root:
   `docker compose exec -T -u root drupal chown -R www-data:www-data /opt/drupal/web/sites/default/files /opt/drupal/private`.
5. Run `drush aabenintra:verify-isolation` once and keep the output with the
   tenant's onboarding record.
6. Flip the deploy back to `run_updb=true` (the push default) for ongoing
   deploys.

The bootstrap is **not** re-runnable on a partially installed database:
recipes refuse to overwrite config that already exists with different values.
To restart, `drush sql:drop -y` (or drop and recreate the volume) and run it
again from the top.

## 8. Secrets contract (`.env.example`)

| Variable | Used by | Notes |
| --- | --- | --- |
| `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_HOST`, `DB_PORT` | MariaDB container (creates the DB and user), settings.php | Root gets a random password (`MYSQL_RANDOM_ROOT_PASSWORD`). `DB_HOST` is the DB container name, never `localhost`. |
| `HASH_SALT` | settings.php | Per tenant, 64 hex chars. Changing it invalidates one-time login links and form tokens. |
| `TRUSTED_HOSTS` | settings.php | One regex for the tenant host. Unset = Drupal accepts any Host header (the `trusted_hosts` check fails). |
| `REDIS_HOST` | compose, settings.php (once the Redis block is enabled) | The Redis container name. |
| `SMTP_HOST`, `SMTP_PORT`, `SMTP_PROTOCOL`, `SMTP_USER`, `SMTP_PASSWORD`, `SMTP_FROM` | settings.php | `SMTP_PASSWORD` set switches `system.mail` to `SMTPMailSystem`, which requires the `smtp` module; the `mail` check fails when the plugin is missing. |

Secrets never enter the repository, the deploy logs or the check output; the
checks print names and hosts only.

## 9. Known pitfalls (from this repository's history)

- **Recipes refuse to overwrite differing config.** A fresh `minimal` +
  `standard` install already owns `core.entity_form_display.user.user.default`
  and the view display; `install_prod.sh` deletes both before the content
  recipe, which ships its own versions. The same applies to
  `language.types`, `language.negotiation` and `language.entity.da` when
  `drush language:add da` ran before the i18n recipe. Symptom: the recipe
  aborts with a config conflict; `recipe_config` then fails.
- **`language` and `group` must be enabled via drush, not via a recipe's
  `install:` list.** Installed through a recipe, their service containers are
  not fully registered (`path_processor_language` missing, `/da` dead). The
  recipes only ship config; the script enables the modules first.
- **`skip_cim` is mandatory.** Running the generic Drupal post-deploy with
  `drush cim` would delete active config. The AabenIntra dispatch always sends
  `skip_cim: "true"`.
- **`run_updb` was off by default until 2026-10-14.** Pushes deployed code
  without `updb` or a snapshot; the 11.4.10 security deploy went out that
  way. It is on for pushes now (#163); keep it that way.
- **Stale theme registry.** New template overrides in the theme sometimes do
  not register until the theme is re-enabled; the bootstrap runs
  `theme:enable aabenintra` twice around a `cr`. After a deploy, `drush cr`
  is enough; if a template still does not pick up, `drush theme:enable
  aabenintra` once more.
- **PHP memory.** Recipe application OOMs at the stock 128M; the image sets
  512M.
- **Bootstrap as root leaves root-owned `files/styles`.** Image derivatives
  then return 500. `public_files` checks for it; the fix is `chown` (section
  7, step 4).
- **Deploy race during manual work.** Every push to `main` recreates the
  Drupal container and wipes `/tmp`. Do not push while a bootstrap is running
  on the host.
- **Environment changes need a recreate.** `.env` edits take effect only on
  `docker compose up -d --force-recreate` (or the `force_recreate` dispatch
  input).
- **Dependabot and the lockfile.** A composer bump merges and deploys on its
  own; the build check protects the image build but not the update hooks.
  Read the contrib release notes for anything with a `hook_update_N`.
