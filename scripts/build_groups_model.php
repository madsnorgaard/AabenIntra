<?php

/**
 * @file
 * Builds the Tip content type + Document enhancements for group sharing.
 * Run: ddev drush scr scripts/build_groups_model.php
 */

declare(strict_types=1);

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;

$display_repo = \Drupal::service('entity_display.repository');
$ctm = \Drupal::service('content_translation.manager');

$ensure_field = function (string $bundle, string $name, string $type, array $storage_settings, array $field_settings, string $label, int $cardinality, array $form_widget, array $view_formatter, bool $translatable = FALSE) use ($display_repo): void {
  if (!FieldStorageConfig::loadByName('node', $name)) {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'type' => $type,
      'cardinality' => $cardinality,
      'settings' => $storage_settings,
    ])->save();
  }
  if (!FieldConfig::loadByName('node', $bundle, $name)) {
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'bundle' => $bundle,
      'label' => $label,
      'translatable' => $translatable,
      'settings' => $field_settings,
    ])->save();
  }
  $display_repo->getFormDisplay('node', $bundle)->setComponent($name, $form_widget)->save();
  $display_repo->getViewDisplay('node', $bundle)->setComponent($name, $view_formatter)->save();
};

// --- Tip content type ---
if (!NodeType::load('tip')) {
  NodeType::create([
    'type' => 'tip',
    'name' => 'Tip',
    'description' => 'A quick, crowd-sourced tip or trick shared with a group.',
    'new_revision' => TRUE,
    'preview_mode' => DRUPAL_OPTIONAL,
  ])->save();
  echo "content type tip created\n";
}
if (!FieldConfig::loadByName('node', 'tip', 'body')) {
  if (!FieldStorageConfig::loadByName('node', 'body')) {
    FieldStorageConfig::create(['field_name' => 'body', 'entity_type' => 'node', 'type' => 'text_with_summary', 'cardinality' => 1])->save();
  }
  FieldConfig::create(['field_name' => 'body', 'entity_type' => 'node', 'bundle' => 'tip', 'label' => 'Body', 'translatable' => TRUE])->save();
}
$display_repo->getFormDisplay('node', 'tip')->setComponent('body', ['type' => 'text_textarea_with_summary'])->save();
$display_repo->getViewDisplay('node', 'tip')->setComponent('body', ['type' => 'text_default', 'label' => 'hidden'])->save();

$ensure_field('tip', 'field_topic', 'entity_reference',
  ['target_type' => 'taxonomy_term'],
  ['handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['topic' => 'topic']]],
  'Topic', -1, ['type' => 'options_buttons'], ['type' => 'entity_reference_label', 'label' => 'inline']);
$ensure_field('tip', 'field_category', 'list_string',
  ['allowed_values' => ['how_to' => 'How-to', 'best_practice' => 'Best practice', 'troubleshooting' => 'Troubleshooting', 'snippet' => 'Snippet']],
  [], 'Category', 1, ['type' => 'options_select'], ['type' => 'list_default', 'label' => 'inline']);
$ensure_field('tip', 'field_attachments', 'file',
  ['uri_scheme' => 'public', 'target_type' => 'file'],
  ['file_extensions' => 'pdf doc docx xls xlsx ppt pptx txt zip png jpg'],
  'Attachments', -1, ['type' => 'file_generic'], ['type' => 'file_default', 'label' => 'above']);

// --- Document enhancements ---
$ensure_field('document', 'body', 'text_with_summary', [], [], 'Body', 1,
  ['type' => 'text_textarea_with_summary'], ['type' => 'text_default', 'label' => 'hidden'], TRUE);
$ensure_field('document', 'field_topics', 'entity_reference',
  ['target_type' => 'taxonomy_term'],
  ['handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['topic' => 'topic']]],
  'Topics', -1, ['type' => 'options_buttons'], ['type' => 'entity_reference_label', 'label' => 'inline']);
$ensure_field('document', 'field_document_file', 'file',
  ['uri_scheme' => 'public', 'target_type' => 'file'],
  ['file_extensions' => 'pdf doc docx xls xlsx ppt pptx txt zip csv'],
  'Files', -1, ['type' => 'file_generic'], ['type' => 'file_table', 'label' => 'above']);

// --- Content translation for Tip ---
$ctm->setEnabled('node', 'tip', TRUE);

echo "GROUPS MODEL BUILD COMPLETE\n";
