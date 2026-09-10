<?php

declare(strict_types=1);

namespace Drupal\azure_storage_browser;

use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Selects the storage backend this site talks to.
 *
 * An Azure storage account is exactly one kind: a StorageV2 account exposes
 * the Blob service, while a FileStorage (premium files) account exposes only
 * the Files service and has no blob endpoint at all. So the two backends are
 * mutually exclusive per site, and the `storage_backend` setting says which is
 * in use — typically overridden per environment in settings.php.
 */
final class AzureStorageBackendResolver {

  public const BACKEND_BLOB = 'blob';
  public const BACKEND_FILE_SHARE = 'file_share';

  /**
   * Used when storage_backend is unset or unrecognised.
   *
   * Azure Files, because it is the one backend every account kind can serve:
   * a FileStorage account exposes only the Files service, and a StorageV2
   * account exposes Files as well as Blob.
   */
  public const DEFAULT_BACKEND = self::BACKEND_FILE_SHARE;

  public function __construct(
    private readonly AzureBlobStorageService $blobStorage,
    private readonly AzureFileShareService $fileShare,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns the backend service for the configured storage kind.
   */
  public function get(): AzureStorageBackendInterface {
    // Exhaustive: getBackendId() only ever returns a valid id.
    return match ($this->getBackendId()) {
      self::BACKEND_BLOB => $this->blobStorage,
      self::BACKEND_FILE_SHARE => $this->fileShare,
    };
  }

  /**
   * Returns the configured backend id, or DEFAULT_BACKEND if unset or unrecognised.
   *
   * An unknown value is a misconfiguration rather than a fatal condition, so it
   * is logged and the default used — the alternative is a white screen on what
   * is only a browse-and-download page.
   */
  public function getBackendId(): string {
    $configured = (string) (
      $this->configFactory->get('azure_storage_browser.settings')->get('storage_backend')
      ?? self::DEFAULT_BACKEND
    );

    if (!in_array($configured, self::backendIds(), TRUE)) {
      $this->logger->warning(
        'Unrecognised storage_backend %backend; falling back to %default. Valid values are: %valid.',
        [
          '%backend' => $configured,
          '%default' => self::DEFAULT_BACKEND,
          '%valid'   => implode(', ', self::backendIds()),
        ]
      );
      return self::DEFAULT_BACKEND;
    }

    return $configured;
  }

  /**
   * All valid backend ids.
   *
   * @return list<string>
   */
  public static function backendIds(): array {
    return [self::BACKEND_BLOB, self::BACKEND_FILE_SHARE];
  }

}
