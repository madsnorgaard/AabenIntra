#!/usr/bin/env bash
#
# Per-tenant backup for an AabenIntra tenant. Runs on the Docker HOST from (or
# pointed at) the tenant's compose directory. See docs/BACKUPS.md.
#
#   scripts/backup_tenant.sh [options]
#
# What it produces under <compose dir>/backups/ (BACKUP_ROOT):
#   db/<tenant>-<UTC ts>.sql.gz              full logical dump of the tenant DB
#   files/<tenant>-<UTC ts>-files.tar.gz     the drupal_files named volume (public files)
#   files/<tenant>-<UTC ts>-private.tar.gz   ./data/private (private files)
#   manifests/<tenant>-<UTC ts>.sha256       sha256sum-compatible checksum list
#   manifests/<tenant>-<UTC ts>.manifest     run summary (host, images, sizes)
#   metrics/aabenintra_backup_<tenant>.prom  node-exporter textfile metrics
#
# Credential handling: the database is dumped CREDENTIAL-BLIND. The dump
# client runs inside the DB container and reads the user/password from that
# container's own environment; nothing is passed on the host command line,
# printed, or written to a log. Files owned by www-data are read through a
# throwaway alpine container, so the script needs no sudo.
#
# Exit status is non-zero on any failure, and the metrics file records the
# outcome either way so a stale/failed backup can alert.
#
# Options (environment variable in brackets, flag wins):
#   --compose-dir DIR        tenant compose directory          [COMPOSE_DIR]  default: parent of this script
#   --tenant SLUG            label for file names and metrics  [TENANT]       default: basename of compose dir
#   --backup-root DIR        where to write                    [BACKUP_ROOT]  default: <compose dir>/backups
#   --retention-days N       keep daily artifacts N days       [BACKUP_RETENTION_DAYS]   default 30
#   --weekly-keep-weeks N    also keep Sunday artifacts N weeks [BACKUP_WEEKLY_KEEP_WEEKS] default 12 (0 = off)
#   --offsite TARGET         copy new artifacts off-site       [OFFSITE_TARGET]  rsync "user@host:/path" or rclone "remote:path"
#   --offsite-tool TOOL      auto|rsync|rclone                 [OFFSITE_TOOL]    default auto
#   --metrics-file PATH      textfile-collector output         [METRICS_FILE]    default <backup root>/metrics/aabenintra_backup_<tenant>.prom
#   --full-files             include regenerable css/js/php/styles in the files archive
#   --no-db | --no-files     skip a component (for ad-hoc runs)
#   -h, --help
set -euo pipefail

usage() { sed -n '2,/^set -euo/p' "$0" | sed '$d' | sed 's/^# \{0,1\}//'; }

die() { echo "backup_tenant: ERROR: $*" >&2; exit 1; }
log() { echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] $*"; }

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
COMPOSE_DIR=${COMPOSE_DIR:-$(cd "$SCRIPT_DIR/.." && pwd)}
TENANT=${TENANT:-}
BACKUP_ROOT=${BACKUP_ROOT:-}
RETENTION_DAYS=${BACKUP_RETENTION_DAYS:-30}
WEEKLY_KEEP_WEEKS=${BACKUP_WEEKLY_KEEP_WEEKS:-12}
OFFSITE_TARGET=${OFFSITE_TARGET:-}
OFFSITE_TOOL=${OFFSITE_TOOL:-auto}
METRICS_FILE=${METRICS_FILE:-}
DB_SERVICE=${DB_SERVICE:-aabenintra_db}
FILES_VOLUME=${FILES_VOLUME:-drupal_files}
PRIVATE_DIR=${PRIVATE_DIR:-}
ALPINE_IMAGE=${ALPINE_IMAGE:-alpine:3}
FULL_FILES=0
DO_DB=1
DO_FILES=1

while [ $# -gt 0 ]; do
  case "$1" in
    --compose-dir)       COMPOSE_DIR=$2; shift 2 ;;
    --tenant)            TENANT=$2; shift 2 ;;
    --backup-root)       BACKUP_ROOT=$2; shift 2 ;;
    --retention-days)    RETENTION_DAYS=$2; shift 2 ;;
    --weekly-keep-weeks) WEEKLY_KEEP_WEEKS=$2; shift 2 ;;
    --offsite)           OFFSITE_TARGET=$2; shift 2 ;;
    --offsite-tool)      OFFSITE_TOOL=$2; shift 2 ;;
    --metrics-file)      METRICS_FILE=$2; shift 2 ;;
    --full-files)        FULL_FILES=1; shift ;;
    --no-db)             DO_DB=0; shift ;;
    --no-files)          DO_FILES=0; shift ;;
    -h|--help)           usage; exit 0 ;;
    *) die "unknown option: $1 (see --help)" ;;
  esac
done

[ -d "$COMPOSE_DIR" ] || die "compose dir not found: $COMPOSE_DIR"
COMPOSE_DIR=$(cd "$COMPOSE_DIR" && pwd)
[ -f "$COMPOSE_DIR/docker-compose.yml" ] || [ -f "$COMPOSE_DIR/compose.yml" ] || die "no docker-compose.yml in $COMPOSE_DIR"
case "$RETENTION_DAYS$WEEKLY_KEEP_WEEKS" in *[!0-9]*) die "retention values must be integers" ;; esac

# Tenant slug: file-name and Prometheus-label safe.
TENANT=${TENANT:-$(basename "$COMPOSE_DIR")}
TENANT=$(printf '%s' "$TENANT" | tr -c 'A-Za-z0-9._-' '-' | sed 's/-*$//')
[ -n "$TENANT" ] || die "empty tenant slug"

BACKUP_ROOT=${BACKUP_ROOT:-$COMPOSE_DIR/backups}
PRIVATE_DIR=${PRIVATE_DIR:-$COMPOSE_DIR/data/private}
METRICS_FILE=${METRICS_FILE:-$BACKUP_ROOT/metrics/aabenintra_backup_${TENANT}.prom}

for tool in docker gzip sha256sum flock date find; do
  command -v "$tool" >/dev/null 2>&1 || die "missing tool: $tool"
done
docker compose version >/dev/null 2>&1 || die "docker compose v2 plugin required"

mkdir -p "$BACKUP_ROOT"/{db,files,manifests,metrics}
chmod 700 "$BACKUP_ROOT"/{db,files,manifests}
# node-exporter runs unprivileged and must be able to read the metrics file.
chmod 755 "$BACKUP_ROOT/metrics"

cd "$COMPOSE_DIR"
compose() { docker compose "$@"; }

# One run at a time per backup root.
exec 9>"$BACKUP_ROOT/.backup.lock"
flock -n 9 || die "another backup is running for $BACKUP_ROOT"

TS=$(date -u +%Y%m%dT%H%M%SZ)
START=$(date +%s)
STATUS=0           # 1 once everything succeeded
BYTES=0
ARTIFACTS=()       # paths relative to BACKUP_ROOT

# ---------------------------------------------------------------------------
# Metrics (node-exporter textfile collector). Written on every exit path.
# The last-success stamp only moves forward on a fully successful run, which
# is what a BackupStale-style alert keys on.
# ---------------------------------------------------------------------------
write_metrics() {
  local now prev succ dur
  now=$(date +%s)
  dur=$((now - START))
  prev=$(awk -v t="$TENANT" '$1 ~ /^aabenintra_backup_last_success_timestamp_seconds/ && index($0, "tenant=\"" t "\"") {print $2}' "$METRICS_FILE" 2>/dev/null || true)
  succ=$prev; [ "$STATUS" -eq 1 ] && succ=$now
  {
    echo "# HELP aabenintra_backup_last_run_timestamp_seconds When the last backup run finished."
    echo "# TYPE aabenintra_backup_last_run_timestamp_seconds gauge"
    echo "aabenintra_backup_last_run_timestamp_seconds{tenant=\"$TENANT\"} $now"
    echo "# HELP aabenintra_backup_last_success_timestamp_seconds When the last fully successful backup run finished."
    echo "# TYPE aabenintra_backup_last_success_timestamp_seconds gauge"
    echo "aabenintra_backup_last_success_timestamp_seconds{tenant=\"$TENANT\"} ${succ:-0}"
    echo "# HELP aabenintra_backup_last_status 1 if the last run succeeded, 0 if it failed."
    echo "# TYPE aabenintra_backup_last_status gauge"
    echo "aabenintra_backup_last_status{tenant=\"$TENANT\"} $STATUS"
    echo "# HELP aabenintra_backup_last_duration_seconds Wall-clock duration of the last run."
    echo "# TYPE aabenintra_backup_last_duration_seconds gauge"
    echo "aabenintra_backup_last_duration_seconds{tenant=\"$TENANT\"} $dur"
    echo "# HELP aabenintra_backup_last_bytes Bytes written by the last run."
    echo "# TYPE aabenintra_backup_last_bytes gauge"
    echo "aabenintra_backup_last_bytes{tenant=\"$TENANT\"} $BYTES"
  } > "$METRICS_FILE.tmp" && chmod 644 "$METRICS_FILE.tmp" && mv "$METRICS_FILE.tmp" "$METRICS_FILE"
}
on_exit() {
  local rc=$?
  [ $rc -ne 0 ] && STATUS=0
  write_metrics || true
  if [ "$STATUS" -eq 1 ]; then
    log "done: tenant=$TENANT artifacts=${#ARTIFACTS[@]} bytes=$BYTES"
  else
    log "FAILED: tenant=$TENANT (exit $rc) - see messages above"
  fi
  exit $rc
}
trap on_exit EXIT

file_size() { stat -c %s "$1"; }
record() {   # record <relative path>
  ARTIFACTS+=("$1")
  BYTES=$((BYTES + $(file_size "$BACKUP_ROOT/$1")))
  log "  wrote $1 ($(numfmt --to=iec "$(file_size "$BACKUP_ROOT/$1")" 2>/dev/null || file_size "$BACKUP_ROOT/$1"))"
}

# ---------------------------------------------------------------------------
# Database
# ---------------------------------------------------------------------------
if [ "$DO_DB" -eq 1 ]; then
  log "database dump from service $DB_SERVICE"
  [ -n "$(compose ps -q "$DB_SERVICE" 2>/dev/null)" ] || die "service $DB_SERVICE is not running in $COMPOSE_DIR"
  out="db/${TENANT}-${TS}.sql.gz"
  tmp="$BACKUP_ROOT/$out.part"
  # The mariadb image exports MARIADB_*; compose passes MYSQL_*; the tenant
  # .env (env_file) adds DB_*. Resolve in that order INSIDE the container so
  # no credential crosses the container boundary.
  compose exec -T "$DB_SERVICE" sh -c '
    d=$(command -v mariadb-dump || command -v mysqldump) || { echo "no dump client in container" >&2; exit 1; }
    exec "$d" --single-transaction --quick --routines --triggers --events \
      -u"${MARIADB_USER:-${MYSQL_USER:-$DB_USER}}" \
      -p"${MARIADB_PASSWORD:-${MYSQL_PASSWORD:-$DB_PASS}}" \
      "${MARIADB_DATABASE:-${MYSQL_DATABASE:-$DB_NAME}}"
  ' | gzip -6 > "$tmp"
  # A dump cut short by a crash has no trailer line.
  gzip -dc "$tmp" | tail -c 512 | grep -q -- '-- Dump completed' || die "dump trailer missing - incomplete dump, not keeping $out"
  mv "$tmp" "$BACKUP_ROOT/$out"; chmod 600 "$BACKUP_ROOT/$out"
  record "$out"
fi

# ---------------------------------------------------------------------------
# Files: public files volume + private directory, read via alpine so that
# www-data-owned trees need no sudo. Ownership and modes are preserved in
# the tar for a faithful restore.
# ---------------------------------------------------------------------------
if [ "$DO_FILES" -eq 1 ]; then
  log "files: resolving volume $FILES_VOLUME"
  db_cid=$(compose ps -q "$DB_SERVICE" 2>/dev/null | head -1)
  project=""
  [ -n "$db_cid" ] && project=$(docker ps --filter "id=$db_cid" --format '{{.Label "com.docker.compose.project"}}' | head -1)
  vol=""
  [ -n "$project" ] && vol=$(docker volume ls -q --filter "label=com.docker.compose.project=$project" --filter "label=com.docker.compose.volume=$FILES_VOLUME" | head -1)
  [ -z "$vol" ] && [ -n "$project" ] && docker volume ls -q | grep -qx "${project}_${FILES_VOLUME}" && vol="${project}_${FILES_VOLUME}"
  [ -n "$vol" ] || die "could not resolve the $FILES_VOLUME volume for project '${project:-?}'"

  excludes=()
  if [ "$FULL_FILES" -eq 0 ]; then
    # Regenerated by Drupal: aggregated assets, compiled Twig, image derivatives.
    excludes=(--exclude=./css --exclude=./js --exclude=./php --exclude=./styles)
  fi
  out="files/${TENANT}-${TS}-files.tar.gz"
  tmp="$BACKUP_ROOT/$out.part"
  docker run --rm -v "$vol":/src:ro "$ALPINE_IMAGE" tar czf - -C /src ${excludes[@]+"${excludes[@]}"} . > "$tmp"
  mv "$tmp" "$BACKUP_ROOT/$out"; chmod 600 "$BACKUP_ROOT/$out"
  record "$out"

  if [ -d "$PRIVATE_DIR" ]; then
    out="files/${TENANT}-${TS}-private.tar.gz"
    tmp="$BACKUP_ROOT/$out.part"
    docker run --rm -v "$PRIVATE_DIR":/src:ro "$ALPINE_IMAGE" tar czf - -C /src . > "$tmp"
    mv "$tmp" "$BACKUP_ROOT/$out"; chmod 600 "$BACKUP_ROOT/$out"
    record "$out"
  else
    log "  private dir $PRIVATE_DIR not found - skipping (fine for a tenant without private files)"
  fi
fi

[ ${#ARTIFACTS[@]} -gt 0 ] || die "nothing was backed up (both --no-db and --no-files?)"

# ---------------------------------------------------------------------------
# Manifest + checksums
# ---------------------------------------------------------------------------
sums="manifests/${TENANT}-${TS}.sha256"
(cd "$BACKUP_ROOT" && sha256sum -- "${ARTIFACTS[@]}" > "$sums")
chmod 600 "$BACKUP_ROOT/$sums"
{
  echo "tenant=$TENANT"
  echo "timestamp=$TS"
  echo "host=$(hostname)"
  echo "compose_dir=$COMPOSE_DIR"
  echo "bytes=$BYTES"
  echo "files_volume=${vol:-skipped}"
  echo "full_files=$FULL_FILES"
  echo "images:"
  compose images 2>/dev/null | sed 's/^/  /' || true
  echo "artifacts:"
  for a in "${ARTIFACTS[@]}"; do echo "  $a"; done
} > "$BACKUP_ROOT/manifests/${TENANT}-${TS}.manifest"
chmod 600 "$BACKUP_ROOT/manifests/${TENANT}-${TS}.manifest"
ARTIFACTS+=("$sums" "manifests/${TENANT}-${TS}.manifest")
log "manifest manifests/${TENANT}-${TS}.manifest"

# ---------------------------------------------------------------------------
# Retention. Artifacts older than RETENTION_DAYS go, unless they were taken
# on a Sunday and WEEKLY_KEEP_WEEKS is set, in which case they stay for
# WEEKLY_KEEP_WEEKS weeks. Only files carrying this tenant's prefix and a
# recognisable timestamp are ever touched.
# ---------------------------------------------------------------------------
log "retention: ${RETENTION_DAYS}d daily, ${WEEKLY_KEEP_WEEKS}w weekly"
now_epoch=$(date +%s)
removed=0
while IFS= read -r -d '' f; do
  name=$(basename "$f")
  ts=$(printf '%s' "$name" | grep -oE '[0-9]{8}T[0-9]{6}Z' | head -1) || true
  [ -n "$ts" ] || continue
  day=${ts:0:8}
  epoch=$(date -u -d "${day:0:4}-${day:4:2}-${day:6:2}" +%s 2>/dev/null) || continue
  age_days=$(( (now_epoch - epoch) / 86400 ))
  [ "$age_days" -gt "$RETENTION_DAYS" ] || continue
  if [ "$WEEKLY_KEEP_WEEKS" -gt 0 ] && [ "$(date -u -d "${day:0:4}-${day:4:2}-${day:6:2}" +%u)" = "7" ] \
     && [ "$age_days" -le $((WEEKLY_KEEP_WEEKS * 7)) ]; then
    continue
  fi
  rm -f -- "$f" && removed=$((removed + 1))
done < <(find "$BACKUP_ROOT/db" "$BACKUP_ROOT/files" "$BACKUP_ROOT/manifests" -maxdepth 1 -type f -name "${TENANT}-*" -print0)
log "  removed $removed expired artifact(s)"

# ---------------------------------------------------------------------------
# Off-site copy of this run's artifacts (the pull model documented in
# docs/BACKUPS.md needs nothing here; this is the push alternative).
# ---------------------------------------------------------------------------
if [ -n "$OFFSITE_TARGET" ]; then
  tool=$OFFSITE_TOOL
  if [ "$tool" = auto ]; then
    # "remote:path" without an @ is an rclone remote; "user@host:path" is rsync.
    if printf '%s' "$OFFSITE_TARGET" | grep -qE '^[A-Za-z0-9_-]+:' && ! printf '%s' "$OFFSITE_TARGET" | grep -q '@' && command -v rclone >/dev/null 2>&1; then
      tool=rclone
    else
      tool=rsync
    fi
  fi
  log "off-site copy via $tool to $OFFSITE_TARGET"
  case "$tool" in
    rsync)
      command -v rsync >/dev/null 2>&1 || die "rsync not installed"
      (cd "$BACKUP_ROOT" && rsync -a --relative -- "${ARTIFACTS[@]}" "$OFFSITE_TARGET/")
      ;;
    rclone)
      command -v rclone >/dev/null 2>&1 || die "rclone not installed"
      for a in "${ARTIFACTS[@]}"; do
        rclone copyto "$BACKUP_ROOT/$a" "$OFFSITE_TARGET/$a"
      done
      ;;
    *) die "unknown --offsite-tool $tool" ;;
  esac
fi

STATUS=1
