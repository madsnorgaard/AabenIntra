# AabenIntra

A modern Drupal 11 company intranet, usable as a SaaS offering or self-hosted on a
company's own server/VPS. Themed Drupal (custom front-end theme + Gin admin) with
progressive-enhancement "islands" for the personalized tile dashboard and search -
authoring and preview stay 100% native.

The whole product is packaged as composable **Drupal recipes** so a fresh tenant is
`composer install` -> bring up containers -> apply the recipe.

## Local development (DDEV)

```bash
ddev start
ddev composer install
# Install the site and apply the AabenIntra recipes:
ddev drush site:install minimal --account-name=admin --account-pass=admin -y
ddev drush recipe ../recipes/aabenintra_base
ddev drush recipe ../recipes/aabenintra_media
ddev drush recipe ../recipes/aabenintra_content
ddev drush cr
```

The `drush recipe` path is relative to the Drupal web root, so recipes in
`web/recipes/<name>` are referenced as `../recipes/<name>` (or use the absolute
container path `/var/www/html/web/recipes/<name>`).

## Demo content (dev / staging only)

`aabenintra_demo` is a self-contained module that seeds realistic intranet demo
content. **Never enable it in production.**

```bash
ddev drush en aabenintra_demo -y
ddev drush aabenintra:demo-seed     # create demo departments, topics, users, stories
ddev drush aabenintra:demo-clear    # remove all demo content
```

## Recipes

| Recipe | Purpose |
| --- | --- |
| `aabenintra_base` | Gin admin, roles, text formats, pathauto/token, admin toolbar |
| `aabenintra_media` | Media types, focal point, responsive + tile S/M/L image styles |
| `aabenintra_content` | Story/Page/Event/Document, taxonomy, view modes, Layout Builder |

More recipes (groups, social, search, dashboard, theme, tenant) land per the
milestones tracked in the GitHub issues.

## License

GPL-2.0-or-later (required for Drupal code).
