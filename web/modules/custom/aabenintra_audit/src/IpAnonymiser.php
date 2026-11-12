<?php

declare(strict_types=1);

namespace Drupal\aabenintra_audit;

/**
 * Truncates IP addresses for data minimisation (GDPR art. 5(1)(c)).
 *
 * IPv4: the last octet is zeroed (/24), e.g. 203.0.113.42 -> 203.0.113.0.
 * IPv6: the host part (last 64 bits) is zeroed (/64),
 * e.g. 2001:db8:85a3:8d3:1319:8a2e:370:7348 -> 2001:db8:85a3:8d3::.
 * Anything that is not a valid IP becomes an empty string.
 */
final class IpAnonymiser {

  public static function anonymise(string $ip): string {
    $ip = trim($ip);
    if ($ip === '') {
      return '';
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== FALSE) {
      $parts = explode('.', $ip);
      $parts[3] = '0';
      return implode('.', $parts);
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== FALSE) {
      $packed = inet_pton($ip);
      if ($packed === FALSE || strlen($packed) !== 16) {
        return '';
      }
      $masked = substr($packed, 0, 8) . str_repeat("\0", 8);
      $result = inet_ntop($masked);
      return $result === FALSE ? '' : $result;
    }
    return '';
  }

  /**
   * TRUE when the address already is in anonymised form.
   */
  public static function isAnonymised(string $ip): bool {
    return $ip === '' || self::anonymise($ip) === $ip;
  }

}
