<?php

declare(strict_types=1);

namespace Drupal\azure_storage_browser;

/**
 * Common contract for the Azure storage backends this module can browse.
 *
 * A storage account is exactly one kind — a StorageV2 account exposes the Blob
 * service, a FileStorage (premium files) account exposes only the Files
 * service — so a site talks to one backend at a time. Which one is selected by
 * the `storage_backend` setting and resolved by
 * @see \Drupal\azure_storage_browser\AzureStorageBackendResolver
 *
 * Implementations flatten their native model to the same shape: Blob names are
 * already full paths, while file shares are hierarchical and get walked
 * recursively, so callers see one flat list either way.
 */
interface AzureStorageBackendInterface {

  /**
   * Returns a flat list of file metadata from the configured location.
   *
   * Each item contains:
   *   - name          (string) full path within the container or share,
   *                            e.g. "backups/daily/db.sql.gz"
   *   - size          (int)    content length in bytes
   *   - last_modified (string) RFC 1123 date string
   *
   * Implementations may include additional keys; callers must not rely on them.
   *
   * @return list<array{name:string,size:int,last_modified:string}>
   *
   * @throws \RuntimeException
   *   On configuration, HTTP, or XML parse errors.
   */
  public function listFiles(): array;

  /**
   * Downloads one object, streaming it directly to $destination.
   *
   * The download is proxied through the Drupal server rather than redirecting
   * the client to a SAS URL, so only the server needs network access to the
   * storage account — which matters when the account's firewall is restricted
   * to IP ranges that cannot include end-user browsers.
   *
   * @param string $path
   *   The full path within the container or share, as returned by listFiles().
   * @param resource $destination
   *   A writable stream, e.g. fopen('php://output', 'wb').
   *
   * @return array{content_type: string, content_length: ?int}
   *   Metadata taken from Azure's response headers.
   *
   * @throws \RuntimeException
   *   On configuration, HTTP, or cURL errors.
   */
  public function downloadFile(string $path, $destination): array;

  /**
   * Validates that the required settings are present, without any network call.
   *
   * Lets callers fail fast — for example redirecting with a friendly message —
   * before committing to a streamed response whose headers cannot be changed
   * once sent.
   *
   * @throws \RuntimeException
   *   If required settings are missing.
   */
  public function assertConfigured(): void;

}
