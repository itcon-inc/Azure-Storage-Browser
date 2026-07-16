<?php

declare(strict_types=1);

namespace Drupal\azure_storage_browser;

/**
 * Shared formatting/filtering helpers for the Blob Storage and File Share
 * browser controllers.
 */
trait AzureStorageDisplayHelpersTrait {

  /**
   * Returns just the trailing filename from a (potentially path-like) name.
   */
  private function formatDisplayName(string $name): string {
    return htmlspecialchars(basename($name), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  /**
   * Returns the lowercase file extension of a name, without the dot.
   */
  private function fileExtension(string $name): string {
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    return strtolower($ext);
  }

  /**
   * Parses a comma-separated extension string into a lowercase array.
   *
   * @return list<string>
   */
  private function parseExtensions(string $raw): array {
    if (trim($raw) === '') {
      return [];
    }
    return array_values(array_filter(
      array_map(
        static fn(string $e) => strtolower(trim($e, " \t.")),
        explode(',', $raw)
      )
    ));
  }

  /**
   * Formats a byte count into a human-readable string.
   */
  private function formatBytes(int $bytes): string {
    if ($bytes >= 1_073_741_824) {
      return number_format($bytes / 1_073_741_824, 2) . ' GB';
    }
    if ($bytes >= 1_048_576) {
      return number_format($bytes / 1_048_576, 2) . ' MB';
    }
    if ($bytes >= 1_024) {
      return number_format($bytes / 1_024, 2) . ' KB';
    }
    return $bytes . ' B';
  }

  /**
   * Formats an RFC 1123 date string into a site-locale-aware date/time string.
   */
  private function formatDate(string $rfc1123): string {
    try {
      $dt = new \DateTimeImmutable($rfc1123, new \DateTimeZone('UTC'));
      return $dt->format('Y-m-d H:i') . ' UTC';
    }
    catch (\Exception) {
      return $rfc1123;
    }
  }

}
