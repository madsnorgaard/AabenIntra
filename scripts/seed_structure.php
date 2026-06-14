<?php

/**
 * @file
 * Seeds a demo kommune org hierarchy + locations, assigns demo users + stories
 * to units, and creates Community groups. Dev/staging only.
 * Run: ddev drush scr scripts/seed_structure.php
 */

declare(strict_types=1);

use Drupal\group\Entity\Group;
use Drupal\taxonomy\Entity\Term;
use Drupal\user\Entity\User;

$etm = \Drupal::entityTypeManager();
$termStorage = $etm->getStorage('taxonomy_term');

/** Create an organisation term with a level + optional parent; returns tid. */
$org = function (string $name, string $level, ?int $parent = NULL) use ($termStorage): int {
  $existing = $termStorage->loadByProperties(['vid' => 'organisation', 'name' => $name]);
  if ($existing) {
    return (int) reset($existing)->id();
  }
  $term = Term::create([
    'vid' => 'organisation',
    'name' => $name,
    'field_org_level' => $level,
    'parent' => $parent ? [$parent] : [],
  ]);
  $term->save();
  return (int) $term->id();
};
$loc = function (string $name) use ($termStorage): int {
  $existing = $termStorage->loadByProperties(['vid' => 'location', 'name' => $name]);
  return $existing ? (int) reset($existing)->id() : (int) (function () use ($name) { $t = Term::create(['vid' => 'location', 'name' => $name]); $t->save(); return $t; })()->id();
};

// --- Hierarchy ---
$kommune = $org('Aabenby Kommune', 'organisation');
$bu = $org('Børn & Unge', 'division', $kommune);
$tm = $org('Teknik & Miljø', 'division', $kommune);
$skoler = $org('Skoler', 'department', $bu);
$drift = $org('Drift', 'department', $tm);
$team_dig = $org('Digitalisering', 'team', $skoler);
$team_paed = $org('Pædagogik', 'team', $skoler);
$team_vej = $org('Vej & Park', 'team', $drift);

$loc_raadhus = $loc('Rådhuset');
$loc_skole = $loc('Skole Nord');

// --- Assign demo users ---
$anna = User::load(2);  // demo.anna -> Team Digitalisering
if ($anna) { $anna->set('field_primary_org_unit', $team_dig)->set('field_location', $loc_skole)->save(); }
$jonas = User::load(3); // demo.jonas -> sibling team (should NOT see anna's team posts)
if ($jonas) { $jonas->set('field_primary_org_unit', $team_paed)->save(); }

// --- Assign stories across the hierarchy to demonstrate the cascade ---
$stories = array_values($etm->getStorage('node')->loadByProperties(['type' => 'story']));
$plan = [
  // index => org unit (NULL = org-wide, no unit)
  0 => NULL, 1 => $kommune, 2 => $bu, 3 => $skoler, 4 => $team_dig,
  5 => $team_paed, 6 => $team_vej, 7 => NULL,
];
foreach ($plan as $i => $unit) {
  if (!isset($stories[$i])) { continue; }
  $node = $stories[$i];
  if ($unit !== NULL) { $node->set('field_org_unit', $unit); }
  $node->save();
}

// --- Community groups (open + secret) ---
$gstorage = $etm->getStorage('group');
if (!$gstorage->loadByProperties(['label' => 'Innovationsnetværk', 'type' => 'community'])) {
  $g = Group::create(['type' => 'community', 'label' => 'Innovationsnetværk', 'uid' => 1, 'field_group_visibility' => 'open']);
  $g->save();
  foreach ([1, 2] as $uid) { $u = User::load($uid); if ($u && !$g->getMember($u)) { $g->addMember($u); } }
}
if (!$gstorage->loadByProperties(['label' => 'Direktionen', 'type' => 'community'])) {
  $g = Group::create(['type' => 'community', 'label' => 'Direktionen', 'uid' => 1, 'field_group_visibility' => 'secret']);
  $g->save();
}

echo "STRUCTURE SEED COMPLETE\n";
echo "anna(team_dig=$team_dig) jonas(team_paed=$team_paed); units: kommune=$kommune bu=$bu skoler=$skoler dig=$team_dig\n";
