<?php

declare(strict_types=1);

namespace Drupal\aabenintra_gdpr\Collector;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Reads the configured reassignment target without depending on the eraser.
 */
trait ReassignTargetTrait {

  /**
   * The uid content is reassigned to (0 = anonymous, like core).
   */
  protected function reassignTarget(ConfigFactoryInterface $configFactory, EntityTypeManagerInterface $entityTypeManager): int {
    $uid = (int) $configFactory->get('aabenintra_gdpr.settings')->get('reassign_uid');
    if ($uid > 0 && $entityTypeManager->getStorage('user')->load($uid) === NULL) {
      return 0;
    }
    return max(0, $uid);
  }

}
