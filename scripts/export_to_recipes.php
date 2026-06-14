<?php

/**
 * @file
 * Exports the active config we created into the AabenIntra recipes.
 *
 * Strips uuid and _core so the config can be imported cleanly by a recipe on
 * any site. Run with: ddev drush scr scripts/export_to_recipes.php
 */

declare(strict_types=1);

use Drupal\Core\Serialization\Yaml;

$storage = \Drupal::service('config.storage');
$base = '/var/www/html/web/recipes';

$map = [
  'aabenintra_media' => [
    'media.type.image',
    'field.storage.media.field_media_image',
    'field.field.media.image.field_media_image',
    'core.entity_form_display.media.image.default',
    'core.entity_view_display.media.image.default',
    'image.style.tile_small',
    'image.style.tile_medium',
    'image.style.tile_large',
  ],
  'aabenintra_content' => [
    'taxonomy.vocabulary.department',
    'taxonomy.vocabulary.topic',
    'taxonomy.vocabulary.audience',
    'node.type.story',
    'node.type.event',
    'node.type.document',
    'field.storage.node.field_lead_image',
    'field.storage.node.field_department',
    'field.storage.node.field_topics',
    'field.storage.node.field_audience',
    'field.storage.node.field_tile_size',
    'field.storage.node.field_pinned',
    'field.storage.node.field_event_date',
    'field.field.node.story.body',
    'field.field.node.story.field_lead_image',
    'field.field.node.story.field_department',
    'field.field.node.story.field_topics',
    'field.field.node.story.field_audience',
    'field.field.node.story.field_tile_size',
    'field.field.node.story.field_pinned',
    'field.field.node.event.body',
    'field.field.node.event.field_event_date',
    'field.field.node.event.field_department',
    'field.field.node.document.field_department',
    'core.entity_form_display.node.story.default',
    'core.entity_view_display.node.story.default',
    'core.entity_form_display.node.event.default',
    'core.entity_view_display.node.event.default',
    'core.entity_form_display.node.document.default',
    'core.entity_view_display.node.document.default',
  ],
];

$written = 0;
$missing = [];
foreach ($map as $recipe => $names) {
  $dir = "$base/$recipe/config";
  if (!is_dir($dir)) {
    mkdir($dir, 0775, TRUE);
  }
  foreach ($names as $name) {
    $data = $storage->read($name);
    if ($data === FALSE) {
      $missing[] = $name;
      continue;
    }
    unset($data['uuid'], $data['_core']);
    file_put_contents("$dir/$name.yml", Yaml::encode($data));
    $written++;
  }
}

echo "Wrote $written config files.\n";
if ($missing) {
  echo "MISSING (not found in active storage):\n - " . implode("\n - ", $missing) . "\n";
}
