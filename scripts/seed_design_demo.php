<?php

/**
 * @file
 * Seeds demo data that exercises the entity + group design templates:
 * an event, group visibility/description/cover image, and a document file.
 * Dev/staging only. Run: ddev drush scr scripts/seed_design_demo.php
 */

declare(strict_types=1);

use Drupal\Core\File\FileExists;
use Drupal\node\Entity\Node;

$etm = \Drupal::entityTypeManager();
$fs = \Drupal::service('file_system');
$term = function (string $vid, string $name) use ($etm): ?int {
  $t = $etm->getStorage('taxonomy_term')->loadByProperties(['vid' => $vid, 'name' => $name]);
  return $t ? (int) reset($t)->id() : NULL;
};

// --- 1. An event node ------------------------------------------------------
$existing = $etm->getStorage('node')->loadByProperties(['type' => 'event', 'title' => 'All-hands autumn kickoff']);
if (!$existing) {
  $node = Node::create([
    'type' => 'event',
    'title' => 'All-hands autumn kickoff',
    'status' => 1,
    'uid' => 2,
    'body' => ['value' => '<p>Join the whole organisation for the autumn kickoff: strategy update, team celebrations and refreshments afterwards. Everyone is welcome.</p>', 'format' => 'basic_html'],
    'field_event_date' => ['value' => '2026-09-03T14:00:00'],
  ]);
  if ($d = $term('organisation', 'Digitalisering')) {
    $node->set('field_department', $d);
  }
  if ($l = $term('location', 'Rådhuset')) {
    $node->set('field_location', $l);
  }
  $node->save();
  echo "Created event node {$node->id()}.\n";
}

// --- 2. A cover-image generator (GD) --------------------------------------
$cover = function (string $label, array $rgb) use ($fs, $etm): ?int {
  if (!function_exists('imagecreatetruecolor')) {
    return NULL;
  }
  $dir = 'public://group-covers';
  $fs->prepareDirectory($dir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);
  [$w, $h] = [1200, 400];
  $img = imagecreatetruecolor($w, $h);
  // Simple diagonal two-tone wash.
  $c1 = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
  $c2 = imagecolorallocate($img, (int) ($rgb[0] * 0.7), (int) ($rgb[1] * 0.7), (int) ($rgb[2] * 0.7));
  imagefilledrectangle($img, 0, 0, $w, $h, $c1);
  $poly = [0, $h, $w, 0, $w, $h];
  imagefilledpolygon($img, $poly, $c2);
  $tmp = $fs->tempnam('temporary://', 'cov') . '.png';
  imagepng($img, $fs->realpath($tmp));
  imagedestroy($img);
  $uri = $fs->saveData(file_get_contents($fs->realpath($tmp)), $dir . '/' . preg_replace('/[^a-z0-9]+/', '-', strtolower($label)) . '.png', FileExists::Replace);
  $file = $etm->getStorage('file')->create(['uri' => $uri, 'status' => 1]);
  $file->save();
  return (int) $file->id();
};

// --- 3. Group visibility + description + cover ----------------------------
$groups = [
  1 => ['open', 'Hjemsted for ingeniør- og driftsteamet. Del dokumentation, vejledninger og tips, og hold dig opdateret på tværs af projekter.', [37, 99, 175]],
  2 => ['open', 'Et åbent netværk på tværs af forvaltninger for alle, der arbejder med innovation og nye arbejdsgange.', [13, 148, 136]],
  3 => ['secret', 'Lukket rum for direktionen.', [109, 40, 217]],
];
foreach ($groups as $gid => [$vis, $desc, $rgb]) {
  $group = $etm->getStorage('group')->load($gid);
  if (!$group) {
    continue;
  }
  $group->set('field_group_visibility', $vis);
  $group->set('field_group_description', ['value' => $desc, 'format' => 'basic_html']);
  if ($group->get('field_group_image')->isEmpty() && ($fid = $cover($group->label(), $rgb))) {
    $group->set('field_group_image', ['target_id' => $fid, 'alt' => $group->label()]);
  }
  $group->save();
  echo "Updated group {$gid} ({$group->label()}): {$vis}.\n";
}

// --- 4. Attach a file to the onboarding document (node 20) -----------------
$doc = Node::load(20);
if ($doc && $doc->bundle() === 'document' && $doc->get('field_document_file')->isEmpty()) {
  $dir = 'public://documents';
  $fs->prepareDirectory($dir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);
  $body = "Engineering onboarding handbook\n\nWelcome to the team. This handbook covers your first week, tooling, and who to ask.\n";
  $uri = $fs->saveData($body, $dir . '/engineering-onboarding-handbook.txt', FileExists::Replace);
  $file = $etm->getStorage('file')->create(['uri' => $uri, 'status' => 1]);
  $file->save();
  $doc->set('field_document_file', ['target_id' => $file->id()]);
  if ($t = $term('topic', 'Announcements')) {
    // no-op if topic missing
  }
  $doc->save();
  echo "Attached file to document node 20.\n";
}

echo "Design demo seed complete.\n";
