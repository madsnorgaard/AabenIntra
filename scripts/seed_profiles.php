<?php

/**
 * @file
 * Seeds demo employee profiles (title/phone/skills/photo + org unit/location)
 * for the directory + org chart. Dev/staging only.
 * Run: ddev drush scr scripts/seed_profiles.php
 */

declare(strict_types=1);

use Drupal\Core\File\FileExists;
use Drupal\taxonomy\Entity\Term;
use Drupal\user\Entity\User;

$etm = \Drupal::entityTypeManager();
$termStorage = $etm->getStorage('taxonomy_term');
$fs = \Drupal::service('file_system');

$tid = function (string $vid, string $name) use ($termStorage): ?int {
  $t = $termStorage->loadByProperties(['vid' => $vid, 'name' => $name]);
  return $t ? (int) reset($t)->id() : NULL;
};
$skill = function (string $name) use ($termStorage): int {
  $t = $termStorage->loadByProperties(['vid' => 'skills', 'name' => $name]);
  if ($t) {
    return (int) reset($t)->id();
  }
  $term = Term::create(['vid' => 'skills', 'name' => $name]);
  $term->save();
  return (int) $term->id();
};

$avatar = function (string $name, array $rgb) use ($fs, $etm): ?int {
  if (!function_exists('imagecreatetruecolor')) {
    return NULL;
  }
  $dir = 'public://avatars';
  $fs->prepareDirectory($dir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);
  $size = 256;
  $img = imagecreatetruecolor($size, $size);
  $bg = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
  $white = imagecolorallocate($img, 255, 255, 255);
  imagefilledrectangle($img, 0, 0, $size, $size, $bg);
  $initials = strtoupper(mb_substr($name, 0, 1));
  if (preg_match('/\s(\S)/u', $name, $m)) {
    $initials .= strtoupper($m[1]);
  }
  imagestring($img, 5, (int) ($size / 2) - 12, (int) ($size / 2) - 8, $initials, $white);
  $tmp = $fs->tempnam('temporary://', 'av') . '.png';
  imagepng($img, $fs->realpath($tmp));
  imagedestroy($img);
  $data = file_get_contents($fs->realpath($tmp));
  $uri = $fs->saveData($data, $dir . '/' . preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) . '.png', FileExists::Replace);
  $file = $etm->getStorage('file')->create(['uri' => $uri, 'status' => 1]);
  $file->save();
  return (int) $file->id();
};

// name => [username, title, unit term name, location, skills[], rgb]
$people = [
  'Anna Madsen' => ['demo.anna', 'Digitaliseringskonsulent', 'Digitalisering', 'Skole Nord', ['Drupal', 'GDPR', 'Project management'], [37, 99, 175]],
  'Jonas Berg' => ['demo.jonas', 'Pædagogisk leder', 'Pædagogik', 'Skole Nord', ['Pedagogy', 'Communication'], [13, 148, 136]],
  'Priya Shah' => ['demo.priya', 'HR-partner', 'Skoler', 'Rådhuset', ['Communication', 'GDPR'], [109, 40, 217]],
  'Mette Sørensen' => ['demo.mette', 'Vejingeniør', 'Vej & Park', 'Rådhuset', ['Maintenance', 'Project management'], [217, 119, 6]],
  'Lars Nielsen' => ['demo.lars', 'Driftschef', 'Drift', 'Rådhuset', ['Maintenance', 'Communication'], [219, 39, 119]],
  'Sofie Hansen' => ['demo.sofie', 'Kommunikationsmedarbejder', 'Børn & Unge', 'Rådhuset', ['Communication'], [82, 60, 200]],
];

$ustorage = $etm->getStorage('user');
$count = 0;
foreach ($people as $display => [$username, $title, $unitName, $locName, $skills, $rgb]) {
  $existing = $ustorage->loadByProperties(['name' => $username]);
  $user = $existing ? reset($existing) : User::create(['name' => $username, 'mail' => $username . '@example.test', 'status' => 1, 'pass' => 'demo']);
  $user->set('field_job_title', $title);
  $user->set('field_phone', '+45 ' . random_int(20000000, 99999999));
  if ($u = $tid('organisation', $unitName)) {
    $user->set('field_primary_org_unit', $u);
  }
  if ($l = $tid('location', $locName)) {
    $user->set('field_location', $l);
  }
  $user->set('field_skills', array_map(static fn(string $s): array => ['target_id' => $skill($s)], $skills));
  if ($fid = $avatar($display, $rgb)) {
    $user->set('field_photo', ['target_id' => $fid, 'alt' => $display]);
  }
  $user->save();
  $count++;
}

echo "Seeded $count employee profiles.\n";
