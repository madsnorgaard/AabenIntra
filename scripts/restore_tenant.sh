#!/usr/bin/env bash
#
# Restore an AabenIntra tenant from artifacts written by backup_tenant.sh.
# Runs on the Docker HOST. See docs/BACKUPS.md for the full procedure and the
# monthly restore test.
#
#   scripts/restore_tenant.sh --from-manifest backups/manifests/<tenant>-<ts>.sha256 [options]
#   scripts/restore_tenant.sh --db backups/db/<tenant>-<ts>.sql.gz [--files ...] [--private ...] [options]
#
# The target is the tenant whose compose directory holds this script, unless
# --target-compose-dir points at another stack (the staging copy used for the
# monthly restore test). The target stack must be UP; the dump is loaded into
# whatever database the target's own aabenintra_db container is configured
# for, so a production dump can be restored into a staging database with a
# different name and credentials. Nothing credential-related crosses the
# container boundary: the client runs inside the DB container and reads its
# own environment.
#
# Order of operations: confirm -> maintenance mode on -> safety dump of the
# current database -> (optional) drop existing tables -> load dump -> extract
# files/private -> drush cr -> maintenance mode off -> print verification.
#
# Options:
#   --from-manifest FILE      restore every artifact listed in a .sha256 manifest (checksums are verified)
#   --db FILE                 database dump (.sql.gz or .sql)
#   --files FILE              public files archive (drupal_files volume)
#   --private FILE            private files archive (./data/private)
#   --target-compose-dir DIR  restore INTO this stack instead of the one holding this script
#   --tenant SLUG             slug for the pre-restore safety dump name (default: basename of target dir)
#   --clean-db                drop all tables in the target database before loading the dump
#                             (needed when the target held a different tenant; the dump only
#                             recreates the tables it contains)
#   --clean-files             empty the target volume/directory before extracting
#   --no-safety-dump          skip the pre-restore dump of the target database
#   --skip-drush              do not toggle maintenance mode or run drush cr (target has no installed site yet)
#   --yes                     do not prompt for confirmation (scripted monthly test)
#   -h, --help
set -euo pipefail

usage() { sed -n '2,/^set -euo/p' "$0" | sed '$d' | sed 's/^# \{0,1\}//'; }
die() { echo "restore_tenant: ERROR: $*" >&2; exit 1; }
log() { echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] $*"; }

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
TARGET_DIR=$(cd "$SCRIPT_DIR/.." && pwd)
MANIFEST=""
DB_FILE=""
FILES_FILE=""
PRIVATE_FILE=""
TENANT=""
CLEAN_DB=0
CLEAN_FILES=0
SAFETY_DUMP=1
SKIP_DRUSH=0
YES=0
DB_SERVICE=${DB_SERVICE:-aabenintra_db}
DRUPAL_SERVICE=${DRUPAL_SERVICE:-drupal}
FILES_VOLUME=${FILES_VOLUME:-drupal_files}
ALPINE_IMAGE=${ALPINE_IMAGE:-alpine:3}

while [ $# -gt 0 ]; do
  case "$1" in
    --from-manifest)      MANIFEST=$2; shift 2 ;;
    --db)                 DB_FILE=$2; shift 2 ;;
    --files)              FILES_FILE=$2; shift 2 ;;
    --private)            PRIVATE_FILE=$2; shift 2 ;;
    --target-compose-dir) TARGET_DIR=$2; shift 2 ;;
    --tenant)             TENANT=$2; shift 2 ;;
    --clean-db)           CLEAN_DB=1; shift ;;
    --clean-files)        CLEAN_FILES=1; shift ;;
    --no-safety-dump)     SAFETY_DUMP=0; shift ;;
    --skip-drush)         SKIP_DRUSH=1; shift ;;
    --yes|-y)             YES=1; shift ;;
    -h|--help)            usage; exit 0 ;;
    *) die "unknown option: $1 (see --help)" ;;
  esac
done

for tool in docker gzip sha256sum; do
  command -v "$tool" >/dev/null 2>&1 || die "missing tool: $tool"
done
docker compose version >/dev/null 2>&1 || die "docker compose v2 plugin required"

# --- resolve artifacts ------------------------------------------------------
abspath() { case "$1" in /*) printf '%s\n' "$1" ;; *) printf '%s/%s\n' "$PWD" "$1" ;; esac; }

if [ -n "$MANIFEST" ]; then
  [ -f "$MANIFEST" ] || die "manifest not found: $MANIFEST"
  MANIFEST=$(abspath "$MANIFEST")
  root=$(cd "$(dirname "$MANIFEST")/.." && pwd)   # backups/manifests/x.sha256 -> backups/
  log "verifying checksums from $(basename "$MANIFEST")"
  (cd "$root" && sha256sum -c --quiet "$MANIFEST") || die "checksum verification failed - refusing to restore from a damaged set"
  while read -r _sum rel; do
    rel=${rel#\*}
    case "$rel" in
      db/*.sql.gz|db/*.sql)  DB_FILE=${DB_FILE:-$root/$rel} ;;
      files/*-files.tar.gz)  FILES_FILE=${FILES_FILE:-$root/$rel} ;;
      files/*-private.tar.gz) PRIVATE_FILE=${PRIVATE_FILE:-$root/$rel} ;;
    esac
  done < "$MANIFEST"
fi
[ -n "$DB_FILE$FILES_FILE$PRIVATE_FILE" ] || die "nothing to restore: give --from-manifest or at least one of --db/--files/--private"
for f in "$DB_FILE" "$FILES_FILE" "$PRIVATE_FILE"; do
  [ -z "$f" ] || [ -f "$f" ] || die "artifact not found: $f"
done
[ -n "$DB_FILE" ] && DB_FILE=$(abspath "$DB_FILE")
[ -n "$FILES_FILE" ] && FILES_FILE=$(abspath "$FILES_FILE")
[ -n "$PRIVATE_FILE" ] && PRIVATE_FILE=$(abspath "$PRIVATE_FILE")

# --- resolve target ---------------------------------------------------------
[ -d "$TARGET_DIR" ] || die "target compose dir not found: $TARGET_DIR"
TARGET_DIR=$(cd "$TARGET_DIR" && pwd)
[ -f "$TARGET_DIR/docker-compose.yml" ] || [ -f "$TARGET_DIR/compose.yml" ] || die "no docker-compose.yml in $TARGET_DIR"
TENANT=${TENANT:-$(basename "$TARGET_DIR")}
TENANT=$(printf '%s' "$TENANT" | tr -c 'A-Za-z0-9._-' '-' | sed 's/-*$//')
PRIVATE_DIR=${PRIVATE_DIR:-$TARGET_DIR/data/private}
cd "$TARGET_DIR"
compose() { docker compose "$@"; }

db_cid=$(compose ps -q "$DB_SERVICE" 2>/dev/null | head -1)
[ -n "$db_cid" ] || die "service $DB_SERVICE is not running in $TARGET_DIR (bring the target stack up first)"
project=$(docker ps --filter "id=$db_cid" --format '{{.Label "com.docker.compose.project"}}' | head -1)
vol=""
if [ -n "$FILES_FILE" ]; then
  [ -n "$project" ] && vol=$(docker volume ls -q --filter "label=com.docker.compose.project=$project" --filter "label=com.docker.compose.volume=$FILES_VOLUME" | head -1)
  [ -z "$vol" ] && [ -n "$project" ] && docker volume ls -q | grep -qx "${project}_${FILES_VOLUME}" && vol="${project}_${FILES_VOLUME}"
  [ -n "$vol" ] || die "could not resolve the $FILES_VOLUME volume for project '${project:-?}'"
fi

drush() { compose exec -T "$DRUPAL_SERVICE" drush "$@"; }
in_db() { compose exec -T "$DB_SERVICE" sh -c "$1"; }
# Client invocation resolved inside the container (mariadb image exports
# MARIADB_*, compose passes MYSQL_*, the tenant .env adds DB_*).
DB_CLIENT='c=$(command -v mariadb || command -v mysql) || exit 1; exec "$c" -u"${MARIADB_USER:-${MYSQL_USER:-$DB_USER}}" -p"${MARIADB_PASSWORD:-${MYSQL_PASSWORD:-$DB_PASS}}" "${MARIADB_DATABASE:-${MYSQL_DATABASE:-$DB_NAME}}"'
DB_DUMP='d=$(command -v mariadb-dump || command -v mysqldump) || exit 1; exec "$d" --single-transaction --quick --routines --triggers --events -u"${MARIADB_USER:-${MYSQL_USER:-$DB_USER}}" -p"${MARIADB_PASSWORD:-${MYSQL_PASSWORD:-$DB_PASS}}" "${MARIADB_DATABASE:-${MYSQL_DATABASE:-$DB_NAME}}"'

# --- confirm ----------------------------------------------------------------
echo
echo "Restore plan"
echo "  target compose dir : $TARGET_DIR"
echo "  compose project    : ${project:-?}"
echo "  database service   : $DB_SERVICE  (clean tables first: $([ $CLEAN_DB -eq 1 ] && echo yes || echo no))"
echo "  database dump      : ${DB_FILE:-<skip>}"
echo "  public files       : ${FILES_FILE:-<skip>}  -> volume ${vol:-n/a}"
echo "  private files      : ${PRIVATE_FILE:-<skip>}  -> $PRIVATE_DIR"
echo "  clean files first  : $([ $CLEAN_FILES -eq 1 ] && echo yes || echo no)"
echo "  safety dump        : $([ $SAFETY_DUMP -eq 1 ] && echo yes || echo no)"
echo "  drush steps        : $([ $SKIP_DRUSH -eq 1 ] && echo skipped || echo "maintenance mode + cr")"
echo
echo "This OVERWRITES data in the target stack."
if [ "$YES" -ne 1 ]; then
  printf 'Type the compose project name (%s) to continue: ' "${project:-$TENANT}"
  read -r answer
  [ "$answer" = "${project:-$TENANT}" ] || die "aborted"
fi

# --- maintenance mode -------------------------------------------------------
MAINT=0
if [ "$SKIP_DRUSH" -eq 0 ]; then
  if drush sset system.maintenance_mode 1 >/dev/null 2>&1; then
    MAINT=1; log "maintenance mode ON"
  else
    log "WARN: could not enable maintenance mode (site not installed yet?) - continuing"
  fi
fi
leave_maintenance() {
  if [ "$MAINT" -eq 1 ]; then
    drush cr >/dev/null 2>&1 || true
    drush sset system.maintenance_mode 0 >/dev/null 2>&1 && log "maintenance mode OFF" || log "WARN: maintenance mode still ON - clear by hand: drush sset system.maintenance_mode 0"
  fi
}
on_err() { log "FAILED - target left in maintenance mode for inspection; the safety dump (if taken) is in backups/db/"; exit 1; }
trap on_err ERR

# --- database ---------------------------------------------------------------
if [ -n "$DB_FILE" ]; then
  if [ "$SAFETY_DUMP" -eq 1 ]; then
    mkdir -p "$TARGET_DIR/backups/db"; chmod 700 "$TARGET_DIR/backups/db"
    safety="$TARGET_DIR/backups/db/${TENANT}-$(date -u +%Y%m%dT%H%M%SZ)-prerestore.sql.gz"
    log "safety dump of current database -> $(basename "$safety")"
    if in_db "$DB_DUMP" | gzip -6 > "$safety"; then
      chmod 600 "$safety"
    else
      rm -f "$safety"
      log "WARN: safety dump failed (empty database?) - continuing without it"
    fi
  fi
  if [ "$CLEAN_DB" -eq 1 ]; then
    log "dropping all tables in the target database"
    in_db "$DB_CLIENT -N -e \"SELECT CONCAT('DROP TABLE IF EXISTS \\\`', table_name, '\\\`;') FROM information_schema.tables WHERE table_schema = DATABASE()\"" \
      | { echo 'SET FOREIGN_KEY_CHECKS=0;'; cat; } | in_db "$DB_CLIENT"
  fi
  log "loading $(basename "$DB_FILE")"
  case "$DB_FILE" in
    *.gz) gzip -dc "$DB_FILE" | in_db "$DB_CLIENT" ;;
    *)    in_db "$DB_CLIENT" < "$DB_FILE" ;;
  esac
  log "database loaded"
fi

# --- files ------------------------------------------------------------------
extract_into() {  # extract_into <archive> <docker -v source> <label>
  local archive=$1 src=$2 label=$3 pre=""
  [ "$CLEAN_FILES" -eq 1 ] && pre='find /dst -mindepth 1 -delete;'
  log "extracting $(basename "$archive") -> $label"
  docker run --rm -i -v "$src":/dst "$ALPINE_IMAGE" sh -c "${pre} cd /dst && tar xzf -" < "$archive"
}
if [ -n "$FILES_FILE" ]; then
  extract_into "$FILES_FILE" "$vol" "volume $vol"
fi
if [ -n "$PRIVATE_FILE" ]; then
  mkdir -p "$PRIVATE_DIR"
  extract_into "$PRIVATE_FILE" "$PRIVATE_DIR" "$PRIVATE_DIR"
fi

# --- finish -----------------------------------------------------------------
trap - ERR
if [ "$SKIP_DRUSH" -eq 0 ]; then
  log "drush cr"
  drush cr >/dev/null 2>&1 || log "WARN: drush cr failed - run it by hand"
  leave_maintenance
fi

cat <<VERIFY

Restore finished. Verify before declaring success:

  cd $TARGET_DIR
  docker compose exec -T $DRUPAL_SERVICE drush status --fields=bootstrap,db-status
  docker compose exec -T $DRUPAL_SERVICE drush aabenintra:validate      # tenant health check
  docker compose exec -T $DRUPAL_SERVICE drush sql:query 'SELECT COUNT(*) FROM node_field_data'
  docker compose exec -T $DRUPAL_SERVICE drush watchdog:show --count=20
  curl -sS -o /dev/null -w '%{http_code}\n' https://<tenant host>/           # expect 200
  curl -sS -o /dev/null -w '%{http_code}\n' https://<tenant host>/user/login # expect 200

Then log in, open a recent story and a document download (private file),
and run a search. Record the outcome in docs/BACKUPS.md (restore test log).
VERIFY
