#!/usr/bin/env bash
#
# Ongoing-deploy post tasks for AabenIntra. Run inside the drupal container:
#
#   docker compose exec -T drupal bash /opt/drupal/scripts/deploy_post.sh
#
# AabenIntra ships schema + config via recipes and module hook_install/update,
# NOT config sync, so this deliberately runs cr + updb only (NO `drush cim`,
# which would delete active config not present in a sync directory).
set -euo pipefail

cd /opt/drupal
drush cr
drush updb --yes
drush cr
# Tenants installed before aabenintra_ops existed need it enabled once; the
# call is a cheap no-op afterwards. Then validate the deploy: a failing check
# fails the post-deploy (set -e). See docs/DEPLOYMENT.md.
drush pm:install aabenintra_ops --yes
drush aabenintra:validate
