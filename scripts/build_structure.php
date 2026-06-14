<?php

/**
 * @file
 * Builds the AabenIntra organisational structure: organisation (hierarchical) +
 * location vocabularies, the org-level term field, and org-unit/location fields
 * on content + users. Run: ddev drush scr scripts/build_structure.php
 */

declare(strict_types=1);

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\taxonomy\Entity\Vocabulary;

$display_repo = \Drupal::service('entity_display.repository');
$ctm = \Drupal::service('content_translation.manager');

/** Ensure a field storage + instance + displays for any entity type/bundle. */
$ensure = function (string $entity_type, string $bundle, string $name, string $type, array $storage_settings, array $field_settings, string $label, int $cardinality, array $widget, array $formatter) use ($display_repo): void {
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
  $display_repo->getFormDisplay($entity_type, $bundle)->setComponent($name, $widget)->save();
  $display_repo->getViewDisplay($entity_type, $bundle)->setComponent($name, $formatter)->save();
};

// --- Vocabularies ---
foreach (['organisation' => 'Organisation', 'location' => 'Location'] as $vid => $name) {
  if (!Vocabulary::load($vid)) {
    Vocabulary::create(['vid' => $vid, 'name' => $name])->save();
    echo "vocabulary $vid created\n";
  }
}

// --- Org level field on organisation terms ---
$ensure('taxonomy_term', 'organisation', 'field_org_level', 'list_string',
  ['allowed_values' => ['organisation' => 'Organisation', 'division' => 'Division', 'department' => 'Department', 'team' => 'Team']],
  [], 'Level', 1, ['type' => 'options_select'], ['type' => 'list_default', 'label' => 'inline']);

// --- Org unit + location refs on content types ---
$orgRef = [
  'storage' => ['target_type' => 'taxonomy_term'],
  'field' => ['handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['organisation' => 'organisation']]],
];
$locRef = [
  'storage' => ['target_type' => 'taxonomy_term'],
  'field' => ['handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['location' => 'location']]],
];
foreach (['story', 'event', 'document'] as $bundle) {
  $ensure('node', $bundle, 'field_org_unit', 'entity_reference', $orgRef['storage'], $orgRef['field'],
    'Organisation unit', 1, ['type' => 'options_select'], ['type' => 'entity_reference_label', 'label' => 'inline']);
  $ensure('node', $bundle, 'field_location', 'entity_reference', $locRef['storage'], $locRef['field'],
    'Location', 1, ['type' => 'options_select'], ['type' => 'entity_reference_label', 'label' => 'inline']);
}

// --- User: primary org unit + location ---
$ensure('user', 'user', 'field_primary_org_unit', 'entity_reference', $orgRef['storage'], $orgRef['field'],
  'Primary organisation unit', 1, ['type' => 'options_select'], ['type' => 'entity_reference_label', 'label' => 'inline']);
$ensure('user', 'user', 'field_location', 'entity_reference', $locRef['storage'], $locRef['field'],
  'Location', 1, ['type' => 'options_select'], ['type' => 'entity_reference_label', 'label' => 'inline']);

// --- Translation: org-unit/location terms translatable ---
foreach (['organisation', 'location'] as $vid) {
  $ctm->setEnabled('taxonomy_term', $vid, TRUE);
}

echo "STRUCTURE BUILD COMPLETE\n";
