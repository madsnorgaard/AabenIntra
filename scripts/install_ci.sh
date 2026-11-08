#!/usr/bin/env bash
#
# Disposable AabenIntra install for CI and local smoke testing.
#
# Mirrors the production bootstrap (scripts/install_prod.sh) step for step -
# same recipe order, the same config:delete workarounds, language/group modules
# enabled via drush, theme re-enabled to refresh the registry, demo content
# seeded - but against a throwaway database so a full site can be stood up in a
# GitHub Actions job without containers. The accessibility (axe) and Lighthouse
# gates in .github/workflows/a11y-perf.yml run against the result.
#
# Usage (from the repository root, after `composer install`):
#
#   scripts/install_ci.sh
#   cd web && PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8080 .ht.router.php
#
# Environment:
#   AI_CI_DB_URL     Database URL for drush site:install. Defaults to an SQLite
#                    file under web/sites/default/files (needs the pdo_sqlite PHP
#                    extension). Set e.g. mysql://user:pass@127.0.0.1:3306/aabenintra
#                    to install against MariaDB/MySQL instead.
#   AI_CI_ADMIN_PASS Password for uid 1 (default: admin-ci). Disposable site only.
#   AI_CI_USER_NAME  Existing demo user used for authenticated checks (default:
#                    demo.anna - she has an org unit, follows channels and so
#                    exercises the dashboard cascade).
#   AI_CI_USER_PASS  Password set on that user (default: ci-tester-pass).
#
# Nothing here is suitable for a real tenant: passwords are known, the DB is a
# local file and the hash salt is whatever drush generated. Use install_prod.sh.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
export PATH="$ROOT/vendor/bin:$PATH"

if ! command -v drush >/dev/null 2>&1; then
  echo "drush not found - run 'composer install' first" >&2
  exit 1
fi

DRUSH="drush --yes --no-interaction"
RECIPES="$ROOT/web/recipes"
SITE_DIR="$ROOT/web/sites/default"
# Two leading slashes after the host = absolute filesystem path (see
# \Drupal\sqlite\Driver\Database\sqlite\Connection::createConnectionOptionsFromUrl),
# so the same file is found whether Drupal is bootstrapped by drush from the
# project root or by the PHP built-in server from web/.
AI_CI_DB_URL="${AI_CI_DB_URL:-sqlite://localhost/$SITE_DIR/files/.ht.sqlite}"
AI_CI_ADMIN_PASS="${AI_CI_ADMIN_PASS:-admin-ci}"
AI_CI_USER_NAME="${AI_CI_USER_NAME:-demo.anna}"
AI_CI_USER_PASS="${AI_CI_USER_PASS:-ci-tester-pass}"

echo "==> Preparing $SITE_DIR"
mkdir -p "$SITE_DIR/files" "$SITE_DIR/files/translations" "$ROOT/private"
chmod -R u+w "$SITE_DIR"
# A settings.php from a previous run (or a stray local copy) would make
# site:install reuse its DB credentials; this script owns the site dir.
if [ -f "$SITE_DIR/settings.php" ] && [ "${AI_CI_KEEP_SETTINGS:-0}" != "1" ]; then
  echo "    Removing previous settings.php (set AI_CI_KEEP_SETTINGS=1 to keep it)"
  chmod u+w "$SITE_DIR/settings.php"
  rm -f "$SITE_DIR/settings.php"
fi

echo "==> drush site:install minimal ($(echo "$AI_CI_DB_URL" | sed -E 's#://[^@/]*@#://***@#'))"
$DRUSH site:install minimal \
  --db-url="$AI_CI_DB_URL" \
  --site-name='AabenIntra CI' \
  --account-name=admin \
  --account-pass="$AI_CI_ADMIN_PASS"

# Drush hardens settings.php after install; re-open it for the CI tweaks below.
chmod u+w "$SITE_DIR" "$SITE_DIR/settings.php"
cat >> "$SITE_DIR/settings.php" <<'PHP'

// --- Added by scripts/install_ci.sh (disposable CI site) ---------------------
$settings['trusted_host_patterns'] = ['^127\.0\.0\.1$', '^localhost$'];
$settings['file_private_path'] = dirname(DRUPAL_ROOT) . '/private';
$settings['file_temp_path'] = sys_get_temp_dir();
// Keep asset aggregation ON so Lighthouse measures what production ships.
$config['system.performance']['css']['preprocess'] = TRUE;
$config['system.performance']['js']['preprocess'] = TRUE;
// No outbound mail from CI.
$config['system.mail']['interface']['default'] = 'test_mail_collector';
// Skip core's remote translation downloads (locale:update) - CI has no need for
// core/contrib Danish strings, the recipe ships the AabenIntra ones.
$config['locale.settings']['translation']['use_source'] = 'local';
PHP

echo "==> Applying recipes: base -> media -> content"
$DRUSH recipe "$RECIPES/aabenintra_base"
$DRUSH recipe "$RECIPES/aabenintra_media"
# Same workaround as install_prod.sh: the content recipe owns the user displays
# and recipes refuse to overwrite differing pre-existing config.
$DRUSH config:delete core.entity_form_display.user.user.default || true
$DRUSH config:delete core.entity_view_display.user.user.default || true
$DRUSH recipe "$RECIPES/aabenintra_content"

echo "==> i18n (modules via drush, then recipe ships config + Danish .po)"
$DRUSH en language locale content_translation config_translation
$DRUSH language:add da || true
# The recipe ships language.types with its own interface-negotiation order, but
# `drush en language` has already created the default (url only). Recipes refuse
# to overwrite differing config, so pre-set the exact values the recipe carries
# and it is skipped as identical. The ordering below mirrors
# web/recipes/aabenintra_i18n/config/language.types.yml.
$DRUSH php:eval '
  \Drupal::service("language_negotiator")->saveConfiguration("language_interface", [
    "language-url" => -10,
    "language-user" => -9,
    "language-browser" => -8,
    "language-selected" => -7,
  ]);
'
$DRUSH recipe "$RECIPES/aabenintra_i18n"
# Intentionally no `drush locale:update` here: it downloads core/contrib .po
# files from localize.drupal.org, which is slow and flaky in CI. The recipe's
# own Danish strings are imported by the recipe.
$DRUSH php:eval '
  $n = \Drupal::service("language_negotiator");
  $n->saveConfiguration("language_interface", [
    "language-url" => -10,
    "language-user" => -9,
    "language-selected" => -8,
  ]);
  \Drupal::service("kernel")->invalidateContainer();
' || true

echo "==> Groups (modules via drush, then finalise relationship plugins)"
$DRUSH en group gnode aabenintra_groups
$DRUSH aabenintra_groups:install-plugins || true
$DRUSH role:perm:add administrator 'administer group' || true
$DRUSH role:perm:add administrator 'access group overview' || true

echo "==> Enabling AabenIntra feature modules"
$DRUSH en \
  aabenintra_theme \
  aabenintra_dashboard \
  aabenintra_compliance \
  aabenintra_directory \
  aabenintra_social \
  aabenintra_kudos \
  aabenintra_channels \
  aabenintra_activity

echo "==> Theme: default = aabenintra, front page = /dashboard"
$DRUSH theme:enable aabenintra
$DRUSH config:set system.theme default aabenintra
$DRUSH config:set system.site page.front /dashboard
$DRUSH theme:enable aabenintra
$DRUSH cr

echo "==> Activity feed backfill"
$DRUSH aabenintra_activity:backfill || true

echo "==> Demo seed (showcase content; all images are generated locally with GD)"
if [ "$(drush sql:query 'SELECT COUNT(*) FROM node_field_data' 2>/dev/null | tail -1 | tr -dc '0-9')" != "" ] \
   && [ "$(drush sql:query 'SELECT COUNT(*) FROM node_field_data' 2>/dev/null | tail -1 | tr -dc '0-9')" -gt 0 ]; then
  echo "    Content already present - skipping demo seed"
else
  $DRUSH en aabenintra_demo
  $DRUSH aabenintra:demo-seed
  $DRUSH scr "$ROOT/scripts/seed_structure.php"
  $DRUSH scr "$ROOT/scripts/seed_profiles.php"
  $DRUSH scr "$ROOT/scripts/seed_design_demo.php"
  $DRUSH scr "$ROOT/scripts/seed_channels.php"
fi

echo "==> Test account for authenticated checks: $AI_CI_USER_NAME"
if ! drush user:information "$AI_CI_USER_NAME" >/dev/null 2>&1; then
  $DRUSH user:create "$AI_CI_USER_NAME" --mail="${AI_CI_USER_NAME}@example.test"
fi
$DRUSH user:password "$AI_CI_USER_NAME" "$AI_CI_USER_PASS"
$DRUSH user:unblock "$AI_CI_USER_NAME" || true

$DRUSH cr

echo
echo "==> CI site ready."
echo "    Serve:  cd web && PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8080 .ht.router.php"
echo "    Login:  $AI_CI_USER_NAME / (AI_CI_USER_PASS)   admin / (AI_CI_ADMIN_PASS)"
