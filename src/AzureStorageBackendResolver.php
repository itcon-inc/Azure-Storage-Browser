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
    return match ($this->getBackendId()) {
      self::BACKEND_FILE_SHARE => $this->fileShare,
      default => $this->blobStorage,
    };
  }

  /**
   * Returns the configured backend id, falling back to blob when unrecognised.
   *
   * An unknown value is a misconfiguration rather than a fatal condition, so it
   * is logged and the default used — the alternative is a white screen on what
   * is only a browse-and-download page.
   */
  public function getBackendId(): string {
    $configured = (string) (
      $this->configFactory->get('azure_storage_browser.settings')->get('storage_backend')
      ?? self::BACKEND_BLOB
    );

    if (!in_array($configured, self::backendIds(), TRUE)) {
      $this->logger->warning(
        'Unrecognised storage_backend %backend; falling back to %default. Valid values are: %valid.',
        [
          '%backend' => $configured,
          '%default' => self::BACKEND_BLOB,
          '%valid'   => implode(', ', self::backendIds()),
        ]
      );
      return self::BACKEND_BLOB;
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
