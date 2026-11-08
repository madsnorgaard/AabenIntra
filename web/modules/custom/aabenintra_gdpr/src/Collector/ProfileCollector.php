<?php

declare(strict_types=1);

namespace Drupal\aabenintra_gdpr\Collector;

use Drupal\aabenintra_gdpr\Anonymizer;
use Drupal\aabenintra_gdpr\ErasureMode;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\file\FileInterface;
use Drupal\user\UserInterface;

/**
 * The account's configurable profile fields (org unit, location, skills,
 * job title, phone, photo, picture, ...). Owned by the user module; the fields
 * themselves come from recipes and aabenintra_directory.
 */
final class ProfileCollector extends CollectorBase {

  public function __construct(
    ModuleHandlerInterface $moduleHandler,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly Anonymizer $anonymizer,
  ) {
    parent::__construct($moduleHandler);
  }

  public function id(): string {
    return 'profile';
  }

  public function label(): string {
    return 'Profile fields';
  }

  public function module(): string {
    return 'user';
  }

  public function count(UserInterface $subject): int {
    return count($this->collect($subject));
  }

  /**
   * One item per non-empty configurable field.
   */
  public function collect(UserInterface $subject): array {
    $items = [];
    foreach ($this->profileFields($subject) as $name => $list) {
      if ($list->isEmpty()) {
        continue;
      }
      $items[] = [
        'field' => $name,
        'label' => (string) $list->getFieldDefinition()->getLabel(),
        'type' => $list->getFieldDefinition()->getType(),
        'values' => $this->values($list),
      ];
    }
    return $items;
  }

  /**
   * Empties every profile field and deletes image/file entities it held.
   *
   * In delete mode the account entity disappears anyway; files referenced
   * only by the account are still removed explicitly because core leaves
   * file_managed rows (and the bytes on disk) behind.
   */
  public function erase(UserInterface $subject, ErasureMode $mode, bool $dryRun): int {
    $touched = 0;
    foreach ($this->profileFields($subject) as $name => $list) {
      if ($list->isEmpty()) {
        continue;
      }
      $touched++;
      if ($dryRun) {
        continue;
      }
      if ($list instanceof EntityReferenceFieldItemListInterface && in_array($list->getFieldDefinition()->getType(), ['image', 'file'], TRUE)) {
        foreach ($list->referencedEntities() as $file) {
          if ($file instanceof FileInterface) {
            $file->delete();
          }
        }
      }
      $subject->set($name, NULL);
    }
    if (!$dryRun && $touched > 0 && $mode === ErasureMode::Anonymise) {
      // Delete mode: the eraser deletes the entity right after; saving here
      // would be wasted work.
      $subject->save();
    }
    return $touched;
  }

  public function erasureNote(ErasureMode $mode): string {
    return 'all profile fields emptied, picture/photo files deleted; name becomes '
      . $this->anonymizer->name(0) . 'N' . ($mode === ErasureMode::Delete ? ' (then the account is deleted)' : '');
  }

  /**
   * Configurable (non-base) fields on the user entity.
   *
   * @return array<string,\Drupal\Core\Field\FieldItemListInterface<\Drupal\Core\Field\FieldItemInterface>>
   */
  private function profileFields(UserInterface $subject): array {
    $base = $this->entityFieldManager->getBaseFieldDefinitions('user');
    $fields = [];
    foreach ($subject->getFields(FALSE) as $name => $list) {
      if (isset($base[$name]) && $name !== 'user_picture') {
        continue;
      }
      if ($list->getFieldDefinition()->isComputed()) {
        continue;
      }
      $fields[$name] = $list;
    }
    return $fields;
  }

  /**
   * Field values in an export-friendly shape (labels for references).
   *
   * @param \Drupal\Core\Field\FieldItemListInterface<\Drupal\Core\Field\FieldItemInterface> $list
   *
   * @return list<mixed>
   */
  private function values(FieldItemListInterface $list): array {
    $out = [];
    if ($list instanceof EntityReferenceFieldItemListInterface) {
      foreach ($list->referencedEntities() as $entity) {
        $value = ['id' => (int) $entity->id(), 'label' => (string) $entity->label()];
        if ($entity instanceof FileInterface) {
          $value['filename'] = $entity->getFilename();
          $value['uri'] = $entity->getFileUri();
          $value['url'] = $entity->createFileUrl(FALSE);
        }
        $out[] = $value;
      }
      return $out;
    }
    foreach ($list as $item) {
      $values = $item->getValue();
      $out[] = count($values) === 1 ? reset($values) : $values;
    }
    return $out;
  }

}
