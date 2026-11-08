<?php

declare(strict_types=1);

namespace Drupal\aabenintra_observability\Logger;

/**
 * Truncates IP addresses the way analytics tools do for GDPR data minimisation.
 *
 * IPv4 keeps the first three octets (/24), IPv6 the first four hextets (/64).
 * The result still localises a request well enough for debugging while no
 * longer identifying a single household or device.
 */
final class IpAnonymizer {

  /**
   * Returns the truncated address, or NULL for anything that is not an IP.
   */
  public static function anonymize(string $ip): ?string {
    $packed = @inet_pton($ip);
    if ($packed === FALSE) {
      return NULL;
    }
    $mask = strlen($packed) === 4
      ? "\xff\xff\xff\x00"
      : str_repeat("\xff", 8) . str_repeat("\x00", 8);
    $result = inet_ntop($packed & $mask);
    return $result === FALSE ? NULL : $result;
  }

}
