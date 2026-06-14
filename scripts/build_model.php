<?php

/**
 * @file
 * One-off builder for the AabenIntra content + media model.
 *
 * Run with: ddev drush scr scripts/build_model.php
 * Produces the config that is then exported into the aabenintra_media and
 * aabenintra_content recipes. Idempotent-ish: skips entities that already exist.
 */

declare(strict_types=1);

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\image\Entity\ImageStyle;
use Drupal\media\Entity\MediaType;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Vocabulary;

$etm = \Drupal::entityTypeManager();
$display_repo = \Drupal::service('entity_display.repository');

/**
 * Ensures a field storage + instance exist, and adds to form/view displays.
 */
$ensure_field = function (string $entity_type, string $bundle, string $name, string $type, array $storage_settings, array $field_settings, string $label, int $cardinality, array $form_widget, array $view_formatter) use ($display_repo): void {
  if (!FieldStorageConfig::loadByName($entity_type, $name)) {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => $entity_type,
      'type' => $type,
      'cardinality' => $cardinality,
      'settings' => $storage_settings,
    ])->save();
  }
  if (!FieldConfig::loadByName($entity_type, $bundle, $name)) {
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'label' => $label,
      'settings' => $field_settings,
    ])->save();
  }
  $display_repo->getFormDisplay($entity_type, $bundle)
    ->setComponent($name, $form_widget)
    ->save();
  $display_repo->getViewDisplay($entity_type, $bundle)
    ->setComponent($name, $view_formatter)
    ->save();
};

// --- Vocabularies -----------------------------------------------------------
foreach ([
  'department' => 'Department',
  'topic' => 'Topic',
  'audience' => 'Audience',
] as $vid => $name) {
  if (!Vocabulary::load($vid)) {
    Vocabulary::create(['vid' => $vid, 'name' => $name])->save();
    echo "vocabulary $vid created\n";
  }
}

// --- Media type: image ------------------------------------------------------
if (!MediaType::load('image')) {
  $media_type = MediaType::create([
    'id' => 'image',
    'label' => 'Image',
    'source' => 'image',
  ]);
  $media_type->save();
  $source = $media_type->getSource();
  $source_field = $source->createSourceField($media_type);
  $source_field->getFieldStorageDefinition()->save();
  $source_field->save();
  $media_type->set('source_configuration', ['source_field' => $source_field->getName()])->save();
  // Displays for the media source field.
  $display_repo->getFormDisplay('media', 'image')
    ->setComponent($source_field->getName(), ['type' => 'image_focal_point', 'settings' => ['preview_image_style' => 'thumbnail', 'progress_indicator' => 'throbber']])
    ->save();
  $display_repo->getViewDisplay('media', 'image')
    ->setComponent($source_field->getName(), ['type' => 'image', 'label' => 'hidden'])
    ->save();
  echo "media type image created (source field {$source_field->getName()})\n";
}

// --- Image styles for tiles (focal-point crops) -----------------------------
$tile_styles = [
  'tile_small' => [600, 600],
  'tile_medium' => [900, 600],
  'tile_large' => [1200, 800],
];
foreach ($tile_styles as $id => [$w, $h]) {
  if (!ImageStyle::load($id)) {
    $style = ImageStyle::create(['name' => $id, 'label' => ucwords(str_replace('_', ' ', $id))]);
    $style->addImageEffect([
      'id' => 'focal_point_scale_and_crop',
      'weight' => 0,
      'data' => ['width' => $w, 'height' => $h, 'crop_type' => 'focal_point'],
    ]);
    $style->save();
    echo "image style $id created\n";
  }
}

// --- Content type: Story ----------------------------------------------------
if (!NodeType::load('story')) {
  NodeType::create([
    'type' => 'story',
    'name' => 'Story',
    'description' => 'A news/update item that appears as a tile on the dashboard.',
    'new_revision' => TRUE,
    'preview_mode' => DRUPAL_OPTIONAL,
    'display_submitted' => TRUE,
  ])->save();
  echo "content type story created\n";
}

// Body (reuse core body storage if present, else create text_with_summary).
if (!FieldStorageConfig::loadByName('node', 'body')) {
  FieldStorageConfig::create([
    'field_name' => 'body',
    'entity_type' => 'node',
    'type' => 'text_with_summary',
    'cardinality' => 1,
  ])->save();
}
if (!FieldConfig::loadByName('node', 'story', 'body')) {
  FieldConfig::create([
    'field_name' => 'body',
    'entity_type' => 'node',
    'bundle' => 'story',
    'label' => 'Body',
  ])->save();
}
$display_repo->getFormDisplay('node', 'story')->setComponent('body', ['type' => 'text_textarea_with_summary'])->save();
$display_repo->getViewDisplay('node', 'story')->setComponent('body', ['type' => 'text_default', 'label' => 'hidden'])->save();

$ensure_field('node', 'story', 'field_lead_image', 'entity_reference',
  ['target_type' => 'media'],
  ['handler' => 'default:media', 'handler_settings' => ['target_bundles' => ['image' => 'image']]],
  'Lead image', 1,
  ['type' => 'media_library_widget'],
  ['type' => 'entity_reference_entity_view', 'label' => 'hidden', 'settings' => ['view_mode' => 'default']],
);
$ensure_field('node', 'story', 'field_department', 'entity_reference',
  ['target_type' => 'taxonomy_term'],
  ['handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['department' => 'department']]],
  'Department', 1,
  ['type' => 'options_select'],
  ['type' => 'entity_reference_label', 'label' => 'inline'],
);
$ensure_field('node', 'story', 'field_topics', 'entity_reference',
  ['target_type' => 'taxonomy_term'],
  ['handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['topic' => 'topic']]],
  'Topics', -1,
  ['type' => 'options_buttons'],
  ['type' => 'entity_reference_label', 'label' => 'inline'],
);
$ensure_field('node', 'story', 'field_audience', 'entity_reference',
  ['target_type' => 'taxonomy_term'],
  ['handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['audience' => 'audience']]],
  'Audience', 1,
  ['type' => 'options_select'],
  ['type' => 'entity_reference_label', 'label' => 'inline'],
);
$ensure_field('node', 'story', 'field_tile_size', 'list_string',
  ['allowed_values' => ['auto' => 'Auto', 'small' => 'Small', 'medium' => 'Medium', 'large' => 'Large']],
  [],
  'Tile size', 1,
  ['type' => 'options_select'],
  ['type' => 'list_default', 'label' => 'inline'],
);
$ensure_field('node', 'story', 'field_pinned', 'boolean',
  [],
  [],
  'Pinned', 1,
  ['type' => 'boolean_checkbox', 'settings' => ['display_label' => TRUE]],
  ['type' => 'boolean', 'label' => 'inline'],
);

// --- Content type: Event ----------------------------------------------------
if (!NodeType::load('event')) {
  NodeType::create(['type' => 'event', 'name' => 'Event', 'new_revision' => TRUE, 'preview_mode' => DRUPAL_OPTIONAL])->save();
  if (!FieldConfig::loadByName('node', 'event', 'body')) {
    FieldConfig::create(['field_name' => 'body', 'entity_type' => 'node', 'bundle' => 'event', 'label' => 'Body'])->save();
  }
  \Drupal::service('entity_display.repository')->getFormDisplay('node', 'event')->setComponent('body', ['type' => 'text_textarea_with_summary'])->save();
  \Drupal::service('entity_display.repository')->getViewDisplay('node', 'event')->setComponent('body', ['type' => 'text_default', 'label' => 'hidden'])->save();
  echo "content type event created\n";
}
$ensure_field('node', 'event', 'field_event_date', 'datetime',
  ['datetime_type' => 'datetime'], [], 'Event date', 1,
  ['type' => 'datetime_default'],
  ['type' => 'datetime_default', 'label' => 'inline'],
);
$ensure_field('node', 'event', 'field_department', 'entity_reference',
  ['target_type' => 'taxonomy_term'],
  ['handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['department' => 'department']]],
  'Department', 1,
  ['type' => 'options_select'],
  ['type' => 'entity_reference_label', 'label' => 'inline'],
);

// --- Content type: Document -------------------------------------------------
if (!NodeType::load('document')) {
  NodeType::create(['type' => 'document', 'name' => 'Document', 'new_revision' => TRUE, 'preview_mode' => DRUPAL_OPTIONAL])->save();
  echo "content type document created\n";
}
$ensure_field('node', 'document', 'field_department', 'entity_reference',
  ['target_type' => 'taxonomy_term'],
  ['handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['department' => 'department']]],
  'Department', 1,
  ['type' => 'options_select'],
  ['type' => 'entity_reference_label', 'label' => 'inline'],
);

echo "MODEL BUILD COMPLETE\n";
