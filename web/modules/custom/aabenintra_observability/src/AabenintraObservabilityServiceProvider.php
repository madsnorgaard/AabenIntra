<?php

declare(strict_types=1);

namespace Drupal\aabenintra_observability;

use Drupal\aabenintra_observability\Logger\ChannelHandlers;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Applies the environment-driven parts of the logging setup.
 *
 * Runs after the site's services.yml has been loaded, so it only adjusts what
 * cannot be expressed statically there: the stderr level, and the optional
 * extra sinks (watchdog table, Sentry) that depend on which modules are
 * enabled and which variables are set.
 */
final class AabenintraObservabilityServiceProvider extends ServiceProviderBase {

  /**
   * Processors for sinks that do their own placeholder/backtrace handling.
   *
   * Drupal-native loggers (dblog, raven) rebuild the context from `extra`
   * and expect the raw message with placeholders, so neither
   * `message_placeholder` nor `filter_backtrace` is applied for them.
   */
  private const DRUPAL_SINK_PROCESSORS = ['current_user', 'request_uri', 'ip', 'referer'];

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    $env = ObservabilityEnvironment::fromServer();

    $container->setParameter('aabenintra_observability.log_level', $env->logLevel);

    if (!$container->hasParameter('monolog.channel_handlers')) {
      return;
    }
    $handlers = $container->getParameter('monolog.channel_handlers');
    if (!is_array($handlers)) {
      return;
    }

    // LOG_DBLOG=1 keeps the "Recent log messages" UI alive next to stdout.
    if ($env->dblog && $container->hasDefinition('logger.dblog')) {
      $handlers = ChannelHandlers::append($handlers, 'default', 'drupal.dblog', NULL, self::DRUPAL_SINK_PROCESSORS);
    }

    // Sentry/GlitchTip only when the raven module is enabled AND a DSN is set;
    // without a DSN the handler would just cost a call per record.
    if ($env->sentryEnabled() && $container->hasDefinition('logger.raven')) {
      $handlers = ChannelHandlers::append($handlers, 'default', 'drupal.raven', NULL, self::DRUPAL_SINK_PROCESSORS);
    }

    $container->setParameter('monolog.channel_handlers', $handlers);
  }

}
