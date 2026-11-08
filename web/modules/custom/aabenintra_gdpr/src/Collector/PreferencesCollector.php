<?php

declare(strict_types=1);

namespace Drupal\aabenintra_gdpr\Collector;

use Drupal\aabenintra_gdpr\ErasureMode;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\user\UserDataInterface;
use Drupal\user\UserInterface;

/**
 * Theme/UI preferences stored in user.data by aabenintra_theme.
 */
final class PreferencesCollector extends CollectorBase {

  private const MODULE = 'aabenintra_theme';

  public function __construct(
    ModuleHandlerInterface $moduleHandler,
    private readonly UserDataInterface $userData,
  ) {
    parent::__construct($moduleHandler);
  }

  public function id(): string {
    return 'preferences';
  }

  public function label(): string {
    return 'Interface preferences';
  }

  public function module(): string {
    return self::MODULE;
  }

  public function count(UserInterface $subject): int {
    return count($this->collect($subject));
  }

  public function collect(UserInterface $subject): array {
    $data = $this->userData->get(self::MODULE, (int) $subject->id());
    if (!is_array($data)) {
      return [];
    }
    $items = [];
    foreach ($data as $name => $value) {
      $items[] = ['name' => $name, 'value' => $value];
    }
    return $items;
  }

  public function erase(UserInterface $subject, ErasureMode $mode, bool $dryRun): int {
    $count = $this->count($subject);
    if (!$dryRun && $count > 0) {
      $this->userData->delete(self::MODULE, (int) $subject->id());
    }
    return $count;
  }

  public function erasureNote(ErasureMode $mode): string {
    return 'user.data entries deleted';
  }

}
