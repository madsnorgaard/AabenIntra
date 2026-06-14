<?php

declare(strict_types=1);

namespace Drupal\aabenintra_kudos\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The kudos recognition wall.
 */
final class KudosController extends ControllerBase {

  public function __construct(
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity.repository'),
      $container->get('date.formatter'),
    );
  }

  public function wall(): array {
    $node_storage = $this->entityTypeManager()->getStorage('node');
    $nids = $node_storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'kudos')
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->range(0, 60)
      ->execute();

    $badges = [];
    foreach ($node_storage->loadMultiple($nids) as $node) {
      $node = $this->entityRepository->getTranslationFromContext($node);
      $recipient = $node->get('field_recipient')->entity;
      $giver = $node->getOwner();
      $badge_key = $node->get('field_badge')->value;
      $badge_label = '';
      if ($badge_key) {
        $allowed = $node->get('field_badge')->getFieldDefinition()->getFieldStorageDefinition()->getSetting('allowed_values');
        $badge_label = $allowed[$badge_key] ?? $badge_key;
      }
      $message = '';
      if (!$node->get('body')->isEmpty()) {
        $message = text_summary((string) $node->get('body')->value, NULL, 240);
        $message = trim(strip_tags($message));
      }
      $cards[] = [
        'title' => $node->label(),
        'url' => $node->toUrl()->toString(),
        'giver' => $giver ? $giver->getDisplayName() : $this->t('Someone'),
        'recipient' => $recipient ? $recipient->getDisplayName() : $this->t('a colleague'),
        'recipient_url' => $recipient ? $recipient->toUrl()->toString() : NULL,
        'badge' => $badge_label,
        'badge_key' => $badge_key,
        'message' => $message,
        'created' => $this->dateFormatter->format($node->getCreatedTime(), 'medium'),
      ];
      if ($badge_key) {
        $badges[$badge_key] = ($badges[$badge_key] ?? 0) + 1;
      }
    }

    return [
      '#theme' => 'aabenintra_kudos_wall',
      '#cards' => $cards ?? [],
      '#total' => count($nids),
      '#give_url' => Url::fromRoute('node.add', ['node_type' => 'kudos'])->toString(),
      '#can_give' => $this->entityTypeManager()->getAccessControlHandler('node')->createAccess('kudos'),
      '#attached' => ['library' => ['aabenintra_kudos/wall']],
      '#cache' => [
        'tags' => ['node_list:kudos'],
        'contexts' => ['user.permissions', 'languages:language_interface'],
      ],
    ];
  }

}
