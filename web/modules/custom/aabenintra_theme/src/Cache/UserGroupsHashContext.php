<?php

declare(strict_types=1);

namespace Drupal\aabenintra_theme\Cache;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Cache context keyed on the user's org unit + group memberships.
 *
 * For group/org-scoped render output (e.g. "recently shared in your groups",
 * org-cascaded lists) so users sharing the same org unit + group set share
 * cache entries - avoiding the per-user explosion a raw 'user' context causes.
 */
final class UserGroupsHashContext implements CacheContextInterface {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly Connection $database,
  ) {}

  public static function getLabel(): string {
    return (string) t('AabenIntra org unit + group memberships');
  }

  public function getContext(): string {
    if ($this->currentUser->isAnonymous()) {
      return 'anon';
    }
    $uid = (int) $this->currentUser->id();
    $org = 'none';
    $account = \Drupal::entityTypeManager()->getStorage('user')->load($uid);
    if ($account && $account->hasField('field_primary_org_unit') && !$account->get('field_primary_org_unit')->isEmpty()) {
      $org = (string) $account->get('field_primary_org_unit')->target_id;
    }
    $group_ids = [];
    if ($this->database->schema()->tableExists('group_relationship_field_data')) {
      $group_ids = $this->database->select('group_relationship_field_data', 'g')
        ->fields('g', ['gid'])
        ->condition('entity_id', $uid)
        ->condition('plugin_id', '%group_membership', 'LIKE')
        ->execute()
        ->fetchCol();
      sort($group_ids);
    }
    return hash('xxh3', $org . '|' . implode(',', array_map('intval', $group_ids)));
  }

  public function getCacheableMetadata(): CacheableMetadata {
    return new CacheableMetadata();
  }

}
