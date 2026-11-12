<?php

declare(strict_types=1);

namespace Drupal\aabenintra_observability\Logger;

/**
 * Edits the monolog `monolog.channel_handlers` container parameter.
 *
 * The parameter accepts two shapes per channel: a plain list of handler names
 * (`['stderr']`) or `handlers: [{name, formatter, processors}]`. The helpers
 * here cope with both so site services.yml files can use whichever they like.
 */
final class ChannelHandlers {

  /**
   * Whether the channel's configuration already names the handler.
   *
   * @param array<string, mixed> $parameter
   *   The full `monolog.channel_handlers` parameter.
   * @param string $channel
   *   The logger channel (`default` is the fallback).
   * @param string $handler
   *   The handler name without the `monolog.handler.` prefix.
   */
  public static function has(array $parameter, string $channel, string $handler): bool {
    $config = $parameter[$channel] ?? [];
    if (!is_array($config)) {
      return FALSE;
    }
    foreach (self::entries($config) as $entry) {
      if (($entry['name'] ?? NULL) === $handler) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Appends a handler (idempotent) and returns the new parameter.
   *
   * @param array<string, mixed> $parameter
   *   The full `monolog.channel_handlers` parameter.
   * @param string $channel
   *   The logger channel (`default` is the fallback).
   * @param string $handler
   *   The handler name without the `monolog.handler.` prefix.
   * @param string|null $formatter
   *   Formatter name without the `monolog.formatter.` prefix, or NULL.
   * @param string[]|null $processors
   *   Processor names for this handler; NULL inherits `monolog.processors`.
   *
   * @return array<string, mixed>
   *   The updated parameter.
   */
  public static function append(array $parameter, string $channel, string $handler, ?string $formatter = NULL, ?array $processors = NULL): array {
    if (self::has($parameter, $channel, $handler)) {
      return $parameter;
    }
    $config = $parameter[$channel] ?? [];
    $entries = is_array($config) ? self::entries($config) : [];

    $entry = ['name' => $handler];
    if ($formatter !== NULL) {
      $entry['formatter'] = $formatter;
    }
    if ($processors !== NULL) {
      $entry['processors'] = $processors;
    }
    $entries[] = $entry;

    $parameter[$channel] = ['handlers' => $entries];
    return $parameter;
  }

  /**
   * Normalises a channel configuration to the nested entry list.
   *
   * @param array<mixed> $config
   *   One channel's configuration in either syntax.
   *
   * @return array<int, array<string, mixed>>
   *   Entries with at least a `name` key.
   */
  private static function entries(array $config): array {
    $raw = array_key_exists('handlers', $config) && is_array($config['handlers'])
      ? $config['handlers']
      : $config;
    $entries = [];
    foreach ($raw as $item) {
      if (is_string($item)) {
        $entries[] = ['name' => $item];
      }
      elseif (is_array($item) && isset($item['name'])) {
        $entries[] = $item;
      }
    }
    return $entries;
  }

}
