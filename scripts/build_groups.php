<?php

/**
 * @file
 * Creates the Workspace group type, installs content plugins, sets member
 * permissions, and seeds one demo workspace. Run: ddev drush scr scripts/build_groups.php
 */

declare(strict_types=1);

use Drupal\group\Entity\GroupType;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\Group;
use Drupal\node\Entity\Node;

$etm = \Drupal::entityTypeManager();

// --- Workspace group type ---
$gt = GroupType::load('workspace');
if (!$gt) {
  $gt = GroupType::create([
    'id' => 'workspace',
    'label' => 'Workspace',
    'description' => 'A team or department workspace for sharing documents and tips.',
    'creator_membership' => TRUE,
  ]);
  $gt->save();
  echo "group type workspace created\n";
}

// --- Install content plugins (Document + Tip as group nodes) ---
$grt_storage = $etm->getStorage('group_relationship_type');
foreach (['group_node:document', 'group_node:tip'] as $plugin) {
  $id = $grt_storage->getRelationshipTypeId('workspace', $plugin);
  if (!$grt_storage->load($id)) {
    $grt_storage->createFromPlugin($gt, $plugin)->save();
    echo "installed plugin $plugin\n";
  }
}

// --- Member permissions (members-only: outsiders get nothing) ---
$member = GroupRole::load('workspace-member');
if ($member) {
  $perms = [
    'view group',
    'create group_node:tip entity', 'view group_node:tip entity', 'update own group_node:tip entity',
    'create group_node:document entity', 'view group_node:document entity', 'update own group_node:document entity',
  ];
  // Filter to permissions the plugins actually define, to avoid invalid grants.
  $available = array_keys(\Drupal::service('group_permission.handler')->getPermissionsByGroupType($gt));
  $valid = array_values(array_intersect($perms, $available));
  $member->grantPermissions($valid)->save();
  echo "member permissions granted: " . implode(', ', $valid) . "\n";
}

// --- Demo workspace seed ---
$existing = $etm->getStorage('group')->loadByProperties(['label' => 'Engineering', 'type' => 'workspace']);
if (!$existing) {
  $group = Group::create(['type' => 'workspace', 'label' => 'Engineering', 'uid' => 1]);
  $group->save();
  // Add demo.anna (uid 2) as a member.
  $anna = \Drupal\user\Entity\User::load(2);
  if ($anna && !$group->getMember($anna)) {
    $group->addMember($anna);
  }
  // Create a Tip + Document and relate them to the group.
  $tip = Node::create([
    'type' => 'tip', 'title' => 'Speed up your local builds', 'uid' => 2, 'status' => 1,
    'body' => ['value' => '<p>Use the build cache and avoid full rebuilds. Run only the affected suite.</p>', 'format' => 'basic_html'],
    'field_category' => 'best_practice',
  ]);
  $tip->save();
  $group->addRelationship($tip, 'group_node:tip');

  $doc = Node::create([
    'type' => 'document', 'title' => 'Engineering onboarding handbook', 'uid' => 1, 'status' => 1,
    'body' => ['value' => '<p>Everything a new engineer needs in week one.</p>', 'format' => 'basic_html'],
  ]);
  $doc->save();
  $group->addRelationship($doc, 'group_node:document');
  echo "demo workspace 'Engineering' seeded (group {$group->id()}) with a tip + document, member demo.anna\n";
}

echo "GROUPS SETUP COMPLETE\n";
