# Backups and disaster recovery per tenant

Every AabenIntra tenant is one compose stack: a MariaDB container, a Drupal
container, a Redis container, a `drupal_files` volume and a `./data/private`
directory. This page defines what is backed up, how often, where the copies
go, how long they are kept, how a restore is done and proven, and what the
tenant can expect in a disaster. Issue: #116.

## Targets (RPO / RTO)

| Target | Value | Meaning |
|---|---|---|
| RPO (recovery point) | 24 hours | At most one working day of content and interactions can be lost. Backups run nightly; the intranet is not a system of record for anything that cannot be re-posted. Tenants that need a tighter RPO run `backup_tenant.sh --no-files` hourly from cron (DB only, a few seconds) and keep the nightly for files. |
| RTO (recovery time) | 4 hours | From the decision to restore until the tenant is usable again. The restore itself takes minutes for the database and tens of minutes for files; the budget covers provisioning a replacement host from the compose repo if the original is gone, DNS propagation, and verification. |
| Restore test | monthly | A real restore into a staging stack, validated and logged. A backup that has not been restored is a hope, not a backup. |

These are proposed defaults for the managed offering and the numbers a
self-hosting kommune should put in its own continuity plan. Publishing them
in the SLA is a contract commitment; keep the restore log below current so
the numbers are evidenced, not asserted.

## What is backed up

| Component | Where it lives | Artifact | Notes |
|---|---|---|---|
| Database | `aabenintra_db` container, `./data/mysql` | `backups/db/<tenant>-<ts>.sql.gz` | Full logical dump: all tables, routines, triggers, events. Holds content, users, groups, activity, kudos, acknowledgements and the ACTIVE CONFIGURATION. |
| Public files | `drupal_files` named volume | `backups/files/<tenant>-<ts>-files.tar.gz` | Uploaded images, documents in public storage, translations. Aggregated CSS/JS, compiled Twig (`php/`) and image derivatives (`styles/`) are excluded by default because Drupal regenerates them; `--full-files` includes them. |
| Private files | `./data/private` | `backups/files/<tenant>-<ts>-private.tar.gz` | Document files with access control, temp uploads. |
| Manifest | | `backups/manifests/<tenant>-<ts>.sha256` + `.manifest` | Checksums (verified on restore), image versions, sizes. |

Not backed up, on purpose:

- **Redis** (`./data/redis`): cache and lock backend only; it rebuilds on
  the first request. Never restore Redis data from a backup.
- **Config sync directory**: AabenIntra does not use config sync. The
  product's configuration is in the recipes and modules in git, and the
  tenant's live configuration is in the database dump. There is nothing
  separate to back up.
- **Code and images**: the repo and the registry are the backup. The
  manifest records which image tags were running, so a rebuild matches.
- **The `.env` file**: contains secrets and must be kept in the operator's
  password manager (Bitwarden), not in a backup set that travels off-site
  unencrypted. A restore onto a new host needs it before `up -d`.
- **Traefik's `acme.json`**: belongs to the shared Traefik stack and is
  covered by the operator's own nightly job; certificates are re-issued
  automatically anyway.

## The scripts

Both live in `scripts/` and run on the Docker host, not inside a container.
They need `docker compose` v2, `gzip`, `sha256sum` and `flock`
(`util-linux`); no sudo, no database client on the host, no credentials.
The dump client runs inside the database container and reads the password
from that container's environment, so nothing secret is ever on a host
command line or in a log.

### `backup_tenant.sh`

```bash
cd ~/docker/<tenant>/
scripts/backup_tenant.sh                       # all defaults
scripts/backup_tenant.sh --tenant kommune-x --offsite backup@vps1:/srv/backups/kommune-x
scripts/backup_tenant.sh --no-files            # DB only, for an hourly schedule
```

Defaults (each overridable by flag or environment variable, see
`--help`): tenant slug = compose directory name, output under
`./backups/`, 30 daily + 12 weekly retention, metrics at
`./backups/metrics/aabenintra_backup_<tenant>.prom`. Artifacts are
`chmod 600`; the metrics directory is world-readable because node-exporter
runs unprivileged. A lock file prevents overlapping runs. The exit status
is non-zero if anything failed, and a truncated dump (no `-- Dump
completed` trailer) is discarded rather than kept.

### `restore_tenant.sh`

```bash
# Into the tenant itself (production restore):
scripts/restore_tenant.sh --from-manifest backups/manifests/<tenant>-<ts>.sha256

# Into a staging stack (restore test), fully scripted:
scripts/restore_tenant.sh --from-manifest /path/to/prod/backups/manifests/<tenant>-<ts>.sha256 \
    --target-compose-dir ~/docker/<tenant>-staging --clean-db --clean-files --yes

# Just the database, from a specific dump:
scripts/restore_tenant.sh --db backups/db/<tenant>-<ts>.sql.gz
```

It verifies checksums, prints the plan and asks you to type the compose
project name (`--yes` skips this), puts the site in maintenance mode,
takes a safety dump of the current database into `backups/db/*-prerestore.sql.gz`,
loads the dump, extracts files, runs `drush cr`, leaves maintenance mode and
prints the verification commands. On error it stops and leaves the site in
maintenance mode so nobody sees a half-restored intranet.

`--clean-db` drops every table in the target database first. A dump only
recreates the tables it contains, so restoring into a stack that previously
held another tenant, or a newer schema, needs it. `--clean-files` does the
same for the volume and private directory. Use both for the staging test;
neither is needed for a same-tenant production restore.

## Schedule

One cron line per tenant on the host, staggered by 15 minutes so dumps do
not compete for I/O. Times are UTC on hosts that run in UTC; on a host in
local time, pick a window in the night of the tenant's time zone.

```cron
# /etc/cron.d/aabenintra-backups  (or the deploy user's crontab)
# m  h  dom mon dow  command
30 2  *   *   *      cd /home/webadmin/docker/kommune-a && scripts/backup_tenant.sh >> /home/webadmin/logs/backup-kommune-a.log 2>&1
45 2  *   *   *      cd /home/webadmin/docker/kommune-b && scripts/backup_tenant.sh >> /home/webadmin/logs/backup-kommune-b.log 2>&1
0  3  *   *   *      cd /home/webadmin/docker/kommune-c && scripts/backup_tenant.sh >> /home/webadmin/logs/backup-kommune-c.log 2>&1
# Optional hourly DB-only tier for a tenant with a 1-hour RPO requirement:
15 *  *   *   *      cd /home/webadmin/docker/kommune-a && BACKUP_RETENTION_DAYS=2 BACKUP_WEEKLY_KEEP_WEEKS=0 BACKUP_ROOT=/home/webadmin/docker/kommune-a/backups-hourly scripts/backup_tenant.sh --no-files >> /home/webadmin/logs/backup-kommune-a-hourly.log 2>&1
```

On a host that already runs a host-wide nightly backup (the operator's
VPS2 job runs at 02:30 and dumps every database it finds, including the
AabenIntra one), shift the per-tenant jobs to 03:15 onwards. The two
overlap on the database; that is intended. The host job is the operator's
all-sites safety net, the per-tenant job is the one a tenant restore uses,
and the one that is checksummed, tested monthly and reported on per tenant.

Rotate the logs (`logrotate` weekly, keep 8) or the hourly tier fills a disk.

## Retention

- **Daily**: every artifact is kept 30 days (`BACKUP_RETENTION_DAYS`).
- **Weekly**: artifacts taken on a Sunday are kept 12 weeks
  (`BACKUP_WEEKLY_KEEP_WEEKS`, 0 disables).
- Retention is applied by the backup run itself, only to files that carry
  the tenant's own prefix and a parseable timestamp. The pre-restore safety
  dumps follow the same rules.
- Off-site copies follow their own retention (below); the script never
  deletes anything remotely.

Disk budget: a tenant with a 500 MB database and 5 GB of files writes
roughly 60 MB (DB, compressed) + 4-5 GB (files, mostly incompressible) per
night. Public files dominate; if that becomes a problem, move the files
tier to a weekly cadence (`--no-files` on weekdays) and accept a 7-day RPO
for files only, or switch the files tier to an incremental tool
(`restic`/`borg`) and keep this script for the database.

## Off-site copy

An on-host backup protects against a bad deploy or a corrupt table. It does
not protect against losing the host. Every tenant needs one of:

1. **Pull from a second host (recommended, what the operator does)**: the
   second host runs `rsync -a --delete -e 'ssh -p <port>'
   user@tenant-host:docker/<tenant>/backups/ /srv/vps2_backups/<tenant>/`
   from cron, 05:00, with a dedicated key that is restricted in
   `authorized_keys` to that rsync command (`command="..."`,
   `no-pty`, `no-port-forwarding`). The production host holds no credential
   for the backup host, so a compromised tenant host cannot reach or wipe
   the off-site copies. `--delete` mirrors the production retention; drop
   it on the pull side and add a `find -mtime +90 -delete` there for a
   longer off-site history.
2. **Push with rsync**: `OFFSITE_TARGET=user@host:/srv/backups/<tenant>` in
   the environment or `--offsite`. Only the artifacts of the current run
   are pushed. Weaker than pull: the tenant host holds an SSH key to the
   backup host. If used, make that key append-only (`rrsync -wo` or a
   restricted `command=`).
3. **Push to S3-compatible storage with rclone**:
   `OFFSITE_TARGET=<remote>:<bucket>/<tenant>`. Choose an EU-resident
   bucket (data sovereignty is a buying criterion for kommuner), enable
   object lock or versioning on the bucket so a push credential cannot
   overwrite history, and set a lifecycle rule for retention.

Whichever path: the off-site copy must be on different hardware, in a
different failure domain, and the credential used must not be able to
delete history.

## Encryption at rest

The scripts write plaintext artifacts in `0700`/`0600` locations. That is
acceptable on the production host (anyone who can read them can read the
database directly) but not for the off-site copy. Two sane choices, not
implemented here so the operator picks one:

- **age** (`age -r age1... -o file.age file`): one recipient key per
  operator, private key in the password manager, nothing on the servers
  can decrypt. Pipe-friendly, no key-server ceremony.
- **GPG**: same model; the operator's host-wide nightly job uses it
  (`gpg --encrypt --recipient <KEYID>`).

Encrypt before the artifact leaves the host (in the cron line, after the
script, or add an `--encrypt-to` step to a wrapper), and test that you can
decrypt with the private key from a laptop before relying on it. Rotating
the key means re-encrypting the retained set, so pick a key pair you can
keep for years and protect the private key accordingly.

## Monitoring

Each run writes a Prometheus textfile at
`backups/metrics/aabenintra_backup_<tenant>.prom`:

```
aabenintra_backup_last_run_timestamp_seconds{tenant="kommune-x"}
aabenintra_backup_last_success_timestamp_seconds{tenant="kommune-x"}   # only moves on a clean run
aabenintra_backup_last_status{tenant="kommune-x"}                      # 1 ok, 0 failed
aabenintra_backup_last_duration_seconds{tenant="kommune-x"}
aabenintra_backup_last_bytes{tenant="kommune-x"}
```

Point node-exporter's `--collector.textfile.directory` at a directory that
collects these files (symlink or `METRICS_FILE=/path/to/shared/metrics/...`
per tenant). Alert rules, matching the operator's `BackupStale` pattern:

```yaml
- alert: AabenIntraBackupStale
  expr: (time() - aabenintra_backup_last_success_timestamp_seconds) > 36 * 3600
  for: 30m
  labels: {severity: critical}
  annotations:
    summary: "Backup of tenant {{ $labels.tenant }} has not succeeded for 36h"
- alert: AabenIntraBackupFailed
  expr: aabenintra_backup_last_status == 0
  for: 10m
  labels: {severity: warning}
- alert: AabenIntraBackupMissing
  expr: absent(aabenintra_backup_last_success_timestamp_seconds)
  for: 1h
  labels: {severity: warning}
```

The third rule catches a tenant whose cron line was never installed. Add
the tenant list to the alert's description if you want it to name the
missing one.

## Monthly restore test

Done once a month per tenant, or once a month for the busiest tenant and
quarterly for the rest if there are many. The GitHub Actions workflow
`.github/workflows/restore-test-reminder.yml` opens an issue "Monthly
restore test due" on the first of each month with the checklist below;
close it with the result.

Procedure:

1. **Staging stack**. A copy of the tenant's compose directory on the same
   or another host: `~/docker/<tenant>-staging/` with its own `.env`
   (different `DB_NAME`, `DB_PASS`, `HASH_SALT`, `TRUSTED_HOSTS` for a
   staging hostname) and no Traefik router, or a router on a staging
   hostname behind the operator's `admin-ipallowlist@file`. Bring it up
   with `docker compose up -d` and wait for the DB to be healthy. The
   staging stack can be torn down after the test; `docker compose down -v`
   removes its volume too.
2. **Pick the artifact**: yesterday's manifest from the production
   `backups/manifests/`, or better, the OFF-SITE copy of it, since that is
   the one a real disaster would use.
3. **Restore**:
   ```bash
   cd ~/docker/<tenant>-staging
   /path/to/AabenIntra/scripts/restore_tenant.sh \
       --from-manifest /srv/vps2_backups/<tenant>/manifests/<tenant>-<ts>.sha256 \
       --target-compose-dir "$PWD" --clean-db --clean-files --yes
   ```
   Note the wall-clock time from start to the "Restore finished" line.
4. **Validate**:
   ```bash
   docker compose exec -T drupal drush aabenintra:validate
   docker compose exec -T drupal drush status --fields=bootstrap,db-status
   docker compose exec -T drupal drush sql:query 'SELECT COUNT(*) FROM node_field_data'   # compare with production
   docker compose exec -T drupal drush uli --uri=https://<staging host>
   ```
   `aabenintra:validate` is the tenant health check (modules, recipes,
   search, Redis, synthetic dashboard + search request); it must exit 0.
   Then, in a browser: dashboard renders with tiles, open a story with an
   image, download a document (private file), run a search, check
   `/admin/reports/dblog` for errors since the restore.
5. **Record** the result in the table below (one line) and close the
   issue. A failed test is a P1: fix the cause, re-run, and record both.
6. **Tear down** staging or leave it for the next test; either way make
   sure no mail can leave it (`SMTP_PASSWORD` unset in the staging `.env`
   disables SMTP in `settings.php`).

### Restore test log

| Date | Tenant | Artifact (ts) | From | Restore time | `aabenintra:validate` | Manual checks | Result | Operator |
|---|---|---|---|---|---|---|---|---|
| _(none yet)_ | | | | | | | | |

## Disaster scenarios

**Bad deploy or bad content change (most common).** Symptoms: errors
after `deploy_post.sh`, a module update that ate data, an editor who
bulk-deleted. The deploy pipeline already takes a pre-deploy DB snapshot
(`run_updb`), and `restore_tenant.sh --db <dump>` restores last night's.
Files rarely need restoring here. Expected downtime: 5-15 minutes. Decide
fast: every hour you wait, the restore point loses an hour of real
content.

**Database corruption.** MariaDB refuses to start, or tables are marked
crashed and `mariadb-check` cannot repair. Stop the stack, move
`./data/mysql` aside (do not delete it yet), start the stack so the image
initialises an empty database from `.env`, then `restore_tenant.sh --db`
with the newest dump that passes checksum verification. Expected: 15-30
minutes plus dump load time. If the newest dump is also suspect, go back a
day; this is what the 30-day retention is for.

**Host loss.** VPS gone, disk gone, or compromised beyond trust. On a new
host: install Docker, create the `web` network and the shared Traefik
stack (its own repo), clone this repo into `~/docker/<tenant>`, recreate
`.env` from the password manager, `docker compose up -d`, then
`restore_tenant.sh --from-manifest` against the OFF-SITE manifest with
`--skip-drush` for the first DB load if the site is not installed (the
dump contains the whole site, so no `install_prod.sh`), then
`drush cr` and `drush updb` in case the code is newer than the dump. Point
DNS at the new host. Expected: 2-4 hours, dominated by host provisioning
and DNS. This is the scenario the RTO is sized for, and the one the
monthly test rehearses.

**Ransomware / destructive access on the host.** Same as host loss, but
assume the on-host backups are gone or poisoned: this is why the off-site
copy must be pulled with a credential the production host does not hold,
or pushed to storage that is append-only. Verify checksums (the restore
script refuses a manifest that does not match) and prefer an artifact from
before the suspected compromise.

**Partial file loss.** A deleted directory in the files volume or
private dir. Restore only the files tier:
`restore_tenant.sh --files <tar> --private <tar>` (without
`--clean-files` it overlays, so nothing newer is lost).

## Related

- `docs/TRAEFIK-HARDENING.md` - edge protection for the same stack.
- `scripts/deploy_post.sh` - what runs on deploy (the pre-deploy snapshot
  lives in the operator's deploy pipeline).
- Operator's host-wide nightly job: `contabo-infrastructure/docs/BACKUPS.md`.
