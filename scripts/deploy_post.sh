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
