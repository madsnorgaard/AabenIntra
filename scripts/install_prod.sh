#!/usr/bin/env bash
#
# First-install bootstrap for an AabenIntra tenant. Run ONCE inside the drupal
# container after the containers are up:
#
#   docker compose exec -T drupal bash /opt/drupal/scripts/install_prod.sh
#
# AabenIntra is built from recipes + module hook_install (NOT config sync), so a
# fresh tenant is bootstrapped imperatively rather than via `drush cim`. The
# sequence honours the documented gotchas: language + group are enabled via
# drush (recipe `install:` does not fully register their service container), and
# the theme is re-enabled to refresh a stale theme registry.
#
# Idempotent-ish: re-running after a completed install skips site:install and
# re-applies recipes/modules (cheap no-ops). Demo seeding is guarded so it does
# not duplicate.
set -euo pipefail

cd /opt/drupal
DRUSH="drush --yes"
RECIPES=/opt/drupal/web/recipes

echo "==> Checking install state"
if drush status --field=bootstrap 2>/dev/null | grep -qi 'Successful'; then
  echo "    Site already installed - skipping site:install"
else
  echo "==> drush site:install minimal"
  # No --account-pass: drush generates one and we hand out a one-time login at
  # the end. Keeps any password out of the repo and the deploy logs as a secret.
  $DRUSH site:install minimal \
    --site-name='AabenIntra' \
    --account-name=admin \
    --no-interaction
fi

echo "==> Applying recipes: base -> media -> content"
$DRUSH recipe "$RECIPES/aabenintra_base"
$DRUSH recipe "$RECIPES/aabenintra_media"
# The content recipe owns the user form/view displays (it adds the org-unit +
# location fields from the structure model). A fresh minimal+standard install
# already created user.user.default displays (with user_picture), which differ
# from the recipe's, and recipes refuse to overwrite differing config. The
# recipe's versions are authoritative (they include user_picture too), so drop
# the pre-existing ones first and let the recipe install its own.
$DRUSH config:delete core.entity_form_display.user.user.default || true
$DRUSH config:delete core.entity_view_display.user.user.default || true
$DRUSH recipe "$RECIPES/aabenintra_content"

echo "==> i18n (modules via drush, then recipe ships config + Danish .po)"
$DRUSH en language locale content_translation config_translation
$DRUSH language:add da || true
$DRUSH recipe "$RECIPES/aabenintra_i18n"
$DRUSH locale:update || true
# Defensive: guarantee URL-first interface negotiation (url ranked above user)
# even if the recipe config did not fully take on this container.
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
# uid 1 does NOT bypass group access (flexible permissions); grant the admin
# role the perms needed to browse every group.
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
# Re-enable to refresh a potentially stale theme registry (template overrides).
$DRUSH theme:enable aabenintra
$DRUSH cr

echo "==> Activity feed backfill"
$DRUSH aabenintra_activity:backfill || true

echo "==> Demo seed (showcase content)"
if drush sql:query "SELECT 1 FROM node_field_data LIMIT 1" >/dev/null 2>&1 \
   && [ "$(drush sql:query 'SELECT COUNT(*) FROM node_field_data' 2>/dev/null | tail -1)" -gt 0 ]; then
  echo "    Content already present - skipping demo seed"
else
  $DRUSH en aabenintra_demo
  $DRUSH aabenintra:demo-seed
  $DRUSH scr /opt/drupal/scripts/seed_structure.php
  $DRUSH scr /opt/drupal/scripts/seed_profiles.php
  $DRUSH scr /opt/drupal/scripts/seed_design_demo.php
  $DRUSH scr /opt/drupal/scripts/seed_channels.php
fi

$DRUSH cr

echo
echo "==> Done. One-time admin login link:"
drush --uri=https://aabenintra.fenixnordic.solutions user:login || true
