<?php

declare(strict_types=1);

namespace Drupal\aabenintra_gdpr\Plugin\QueueWorker;

use Drupal\aabenintra_gdpr\ExportRequestStorage;
use Drupal\aabenintra_gdpr\GdprExporter;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Runs a queued self-service export and mails the one-time download link.
 */
#[QueueWorker(
  id: 'aabenintra_gdpr_export',
  title: new TranslatableMarkup('GDPR data export requests'),
  cron: ['time' => 60],
)]
final class ExportRequestWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * @param array<string,mixed> $configuration
   * @param array<string,mixed> $plugin_definition
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    protected ExportRequestStorage $requests,
    protected GdprExporter $exporter,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MailManagerInterface $mailManager,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * @param array<string,mixed> $configuration
   * @param array<string,mixed> $plugin_definition
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('aabenintra_gdpr.requests'),
      $container->get('aabenintra_gdpr.exporter'),
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.mail'),
      $container->get('datetime.time'),
      $container->get('logger.channel.aabenintra_gdpr'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * Failures are recorded on the request row and the item is dropped (not
   * re-queued): the user sees "failed" in their request list and can ask
   * again after the rate-limit window, while an operator sees the message in
   * the admin listing and the log.
   */
  public function processItem($data): void {
    $id = (int) ($data['request_id'] ?? 0);
    $row = $id > 0 ? $this->requests->load($id) : NULL;
    if ($row === NULL) {
      return;
    }
    if ($row['status'] !== ExportRequestStorage::STATUS_PENDING) {
      return;
    }
    $this->requests->markProcessing($id);
    $subject = $this->entityTypeManager->getStorage('user')->load((int) $row['uid']);
    if (!$subject instanceof UserInterface || !$subject->isActive()) {
      $this->requests->markFailed($id, 'Account no longer exists or is blocked.');
      return;
    }
    try {
      $uri = $this->exporter->export($subject);
      $token = ExportRequestStorage::generateToken();
      $expires = $this->time->getRequestTime() + $this->exporter->retentionSeconds();
      $this->requests->markReady($id, $uri, $token, $expires);
      $mail = $subject->getEmail();
      if ($mail === NULL || $mail === '') {
        $this->logger->warning('Export @id is ready but uid @uid has no e-mail address; the link can only be reached by an administrator re-issuing it.', ['@id' => $id, '@uid' => $subject->id()]);
        return;
      }
      $result = $this->mailManager->mail('aabenintra_gdpr', 'export_ready', $mail, $subject->getPreferredLangcode(), [
        'token' => $token,
        'name' => $subject->getDisplayName(),
        'expires' => $expires,
      ]);
      if (empty($result['result'])) {
        $this->logger->error('Export @id is ready but the notification e-mail to uid @uid could not be sent.', ['@id' => $id, '@uid' => $subject->id()]);
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('Export request @id failed: @message', ['@id' => $id, '@message' => $e->getMessage()]);
      $this->requests->markFailed($id, $e->getMessage());
    }
  }

}
