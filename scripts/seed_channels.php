<?php

/**
 * @file
 * Demo seed for channels: a few follows + one out-of-cascade followed story.
 *
 * Run: ddev drush scr scripts/seed_channels.php
 */

declare(strict_types=1);

use Drupal\node\Entity\Node;

$follow = \Drupal::service('aabenintra_channels.follow');
$termStorage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$nodeStorage = \Drupal::entityTypeManager()->getStorage('node');

$tidByName = static function (string $vid, string $name) use ($termStorage): int {
  $terms = $termStorage->loadByProperties(['vid' => $vid, 'name' => $name]);
  return $terms ? (int) reset($terms)->id() : 0;
};

$policies = $tidByName('topic', 'Policies');
$howto = $tidByName('topic', 'How-to');
$wins = $tidByName('topic', 'Wins');
$vejpark = $tidByName('organisation', 'Vej & Park');

// Anna (uid 2, Digitalisering) follows Policies + How-to; Jonas (uid 3) Wins.
foreach ([2 => [$policies, $howto], 3 => [$wins]] as $uid => $tids) {
  foreach (array_filter($tids) as $tid) {
    if (!$follow->isFollowing($uid, 'topic', $tid)) {
      $follow->toggle($uid, 'topic', $tid);
    }
  }
}

// A story OUTSIDE Anna's org cascade (targeted at Vej & Park) but in a channel
// she follows (Policies) - so it should reach her ONLY via the follow, and not
// reach Jonas at all.
$title = 'New leave policy (Vej & Park)';
$exists = $nodeStorage->loadByProperties(['title' => $title]);
if (!$exists && $policies && $vejpark) {
  Node::create([
    'type' => 'story',
    'title' => $title,
    'status' => 1,
    'uid' => 5,
    'body' => [
      'value' => 'Updated leave policy for the Roads & Parks team and anyone following the Policies channel.',
      'format' => 'basic_html',
    ],
    'field_topics' => [['target_id' => $policies]],
    'field_org_unit' => [['target_id' => $vejpark]],
  ])->save();
  echo "Created cross-channel demo story.\n";
}

echo "Channels demo seeded (anna follows Policies/How-to, jonas follows Wins).\n";
