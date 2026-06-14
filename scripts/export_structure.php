<?php

/**
 * @file
 * Exports the org/location structure config into the aabenintra_content recipe.
 * Strips uuid/_core. Run: ddev drush scr scripts/export_structure.php
 */

declare(strict_types=1);

use Drupal\Core\Serialization\Yaml;

$storage = \Drupal::service('config.storage');
$dir = '/var/www/html/web/recipes/aabenintra_content/config';

$names = [
  'taxonomy.vocabulary.organisation',
  'taxonomy.vocabulary.location',
  'field.storage.taxonomy_term.field_org_level',
  'field.field.taxonomy_term.organisation.field_org_level',
  'field.storage.node.field_org_unit',
  'field.storage.node.field_location',
  'field.storage.user.field_primary_org_unit',
  'field.storage.user.field_location',
  'core.entity_form_display.taxonomy_term.organisation.default',
  'core.entity_view_display.taxonomy_term.organisation.default',
  'core.entity_form_display.user.user.default',
  'core.entity_view_display.user.user.default',
  'language.content_settings.taxonomy_term.organisation',
  'language.content_settings.taxonomy_term.location',
];
foreach (['story', 'event', 'document'] as $b) {
  $names[] = "field.field.node.$b.field_org_unit";
  $names[] = "field.field.node.$b.field_location";
  $names[] = "core.entity_form_display.node.$b.default";
  $names[] = "core.entity_view_display.node.$b.default";
}
$names[] = 'field.field.user.user.field_primary_org_unit';
$names[] = 'field.field.user.user.field_location';

$written = 0; $missing = [];
foreach ($names as $name) {
  $data = $storage->read($name);
  if ($data === FALSE) { $missing[] = $name; continue; }
  unset($data['uuid'], $data['_core']);
  file_put_contents("$dir/$name.yml", Yaml::encode($data));
  $written++;
}
echo "Wrote $written structure config files.\n";
if ($missing) { echo "MISSING:\n - " . implode("\n - ", $missing) . "\n"; }
