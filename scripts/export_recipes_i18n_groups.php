<?php

/**
 * @file
 * Exports the live i18n + groups + document config into recipes.
 * Strips uuid/_core. Run: ddev drush scr scripts/export_recipes_i18n_groups.php
 */

declare(strict_types=1);

use Drupal\Core\Serialization\Yaml;

$storage = \Drupal::service('config.storage');
$base = '/var/www/html/web/recipes';

$map = [
  'aabenintra_content' => [
    // Document enhancements + translatable bodies (re-export over earlier files).
    'field.storage.node.field_document_file',
    'field.field.node.document.body',
    'field.field.node.document.field_topics',
    'field.field.node.document.field_document_file',
    'field.field.node.story.body',
    'field.field.node.event.body',
    'core.entity_form_display.node.document.default',
    'core.entity_view_display.node.document.default',
  ],
  'aabenintra_i18n' => [
    'language.entity.da',
    'language.negotiation',
    'language.types',
    'language.content_settings.node.story',
    'language.content_settings.node.event',
    'language.content_settings.node.document',
    'language.content_settings.taxonomy_term.department',
    'language.content_settings.taxonomy_term.topic',
    'language.content_settings.taxonomy_term.audience',
  ],
  'aabenintra_groups' => [
    'group.type.workspace',
    'group.role.workspace-member',
    'group.relationship_type.workspace-group_membership',
    'group.relationship_type.workspace-group_node-document',
    'group.relationship_type.workspace-group_node-tip',
    'node.type.tip',
    'field.storage.node.field_topic',
    'field.storage.node.field_category',
    'field.storage.node.field_attachments',
    'field.field.node.tip.body',
    'field.field.node.tip.field_topic',
    'field.field.node.tip.field_category',
    'field.field.node.tip.field_attachments',
    'core.entity_form_display.node.tip.default',
    'core.entity_view_display.node.tip.default',
    'language.content_settings.node.tip',
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
  echo "MISSING:\n - " . implode("\n - ", $missing) . "\n";
}
