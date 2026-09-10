<?php

declare(strict_types=1);

namespace Drupal\azure_storage_browser;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Service for interacting with Azure Files (classic file shares) via REST API.
 *
 * This implementation uses the Azure Files REST API directly with
 * HMAC-SHA256 Shared Key authentication, requiring no external SDK.
 *
 * Unlike Blob Storage, Azure File Shares are hierarchical (directories +
 * files), so listing requires recursive traversal of the directory tree.
 *
 * Downloads are proxied through this service (rather than redirecting the
 * client to a SAS URL) so that only the Drupal server needs network access
 * to the storage account — useful when the account's firewall restricts
 * access to specific IP ranges that don't include end-user browsers.
 */
final class AzureFileShareService implements AzureStorageBackendInterface {

  use StringTranslationTrait;
  use AzureRestClientTrait;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  // ---------------------------------------------------------------------------
  // Public API
  // ---------------------------------------------------------------------------

  /**
   * {@inheritdoc}
   *
   * Azure Files is hierarchical, so the configured directory is walked
   * recursively to produce the flat list the interface promises.
   */
  public function listFiles(): array {
    $config = $this->getConfig();
    $rootDirectory = trim($config['directory_path'], '/');

    return $this->listDirectoryRecursive($config, $rootDirectory);
  }

  /**
   * {@inheritdoc}
   */
  public function downloadFile(string $path, $destination): array {
    $config  = $this->getConfig();
    $account = $config['account_name'];
    $share   = $config['share_name'];

    $resourcePath = '/' . $share . '/' . ltrim($path, '/');
    $url = $this->buildUrl($account, 'file', $resourcePath, [], $config['endpoint']);

    $date = $this->utcDate();
    $headers = [
      'x-ms-date'    => $date,
      // Do NOT "align" this with the Blob service's 2022-04-01: the Files
      // service rejects that version outright with InvalidHeaderValue on the
      // nesrvaprdsa account. Verified against the real account 2026-09-09.
      'x-ms-version' => '2020-10-02',
    ];

    $canonicalisedHeaders = $this->canonicaliseHeaders($headers);
    $canonicalisedResource = $this->canonicaliseResource($account, $resourcePath);

    $stringToSign = implode("\n", [
      'GET',   // HTTP Verb
      '',      // Content-Encoding
      '',      // Content-Language
      '',      // Content-Length (empty for GET)
      '',      // Content-MD5
      '',      // Content-Type
      '',      // Date (empty when x-ms-date is used)
      '',      // If-Modified-Since
      '',      // If-Match
      '',      // If-None-Match
      '',      // If-Unmodified-Since
      '',      // Range
      $canonicalisedHeaders,
      $canonicalisedResource,
    ]);

    $headers['Authorization'] = $this->buildSharedKeyAuth($account, $config['account_key'], $stringToSign);

    return $this->httpGetStream($url, $headers, $destination);
  }

  /**
   * {@inheritdoc}
   */
  public function assertConfigured(): void {
    $this->getConfig();
  }

  // ---------------------------------------------------------------------------
  // Internal helpers
  // ---------------------------------------------------------------------------

  /**
   * Recursively lists files under a directory path within the share.
   *
   * Azure Files exposes "List Directories and Files", which only returns the
   * immediate children of a directory (similar to a filesystem `ls`), so
   * subdirectories must be walked individually to build a flat file list.
   *
   * @param array{account_name:string,account_key:string,share_name:string,directory_path:string,endpoint:string} $config
   *
   * @return list<array{name:string,size:int,last_modified:string}>
   */
  private function listDirectoryRecursive(array $config, string $directoryPath, int $depth = 0): array {
    // Guard against pathological directory structures / accidental cycles.
    if ($depth > 20) {
      return [];
    }

    $entries = $this->listDirectorySinglePage($config, $directoryPath);

    $files = [];
    foreach ($entries['files'] as $file) {
      $fullPath = $directoryPath === '' ? $file['name'] : $directoryPath . '/' . $file['name'];
      $files[] = [
        'name'          => $fullPath,
        'size'          => $file['size'],
        'last_modified' => $file['last_modified'],
      ];
    }

    foreach ($entries['directories'] as $dirName) {
      $subPath = $directoryPath === '' ? $dirName : $directoryPath . '/' . $dirName;
      $files = array_merge($files, $this->listDirectoryRecursive($config, $subPath, $depth + 1));
    }

    return $files;
  }

  /**
   * Calls "List Directories and Files" for a single directory, following
   * continuation markers until the full listing for that directory is read.
   *
   * @param array{account_name:string,account_key:string,share_name:string,directory_path:string,endpoint:string} $config
   *
   * @return array{files: list<array{name:string,size:int,last_modified:string}>, directories: list<string>}
   */
  private function listDirectorySinglePage(array $config, string $directoryPath): array {
    $account = $config['account_name'];
    $share   = $config['share_name'];

    $files = [];
    $directories = [];
    $marker = null;

    do {
      $queryParams = [
        'restype'    => 'directory',
        'comp'       => 'list',
        'maxresults' => '5000',
      ];
      if ($marker !== null) {
        $queryParams['marker'] = $marker;
      }

      $resourcePath = '/' . $share . ($directoryPath !== '' ? '/' . $directoryPath : '');

      $url = $this->buildUrl($account, 'file', $resourcePath, $queryParams, $config['endpoint']);

      $date = $this->utcDate();
      $headers = [
        'x-ms-date'    => $date,
        // See the note in downloadFile(): 2022-04-01 is rejected here.
        'x-ms-version' => '2020-10-02',
      ];

      $canonicalisedHeaders = $this->canonicaliseHeaders($headers);
      $canonicalisedResource = $this->canonicaliseResource($account, $resourcePath, $queryParams);

      $stringToSign = implode("\n", [
        'GET',   // HTTP Verb
        '',      // Content-Encoding
        '',      // Content-Language
        '',      // Content-Length (empty for GET)
        '',      // Content-MD5
        '',      // Content-Type
        '',      // Date (empty when x-ms-date is used)
        '',      // If-Modified-Since
        '',      // If-Match
        '',      // If-None-Match
        '',      // If-Unmodified-Since
        '',      // Range
        $canonicalisedHeaders,
        $canonicalisedResource,
      ]);

      $headers['Authorization'] = $this->buildSharedKeyAuth($account, $config['account_key'], $stringToSign);

      $response = $this->httpGet($url, $headers);
      $parsed = $this->parseListFilesXml($response);

      $files = array_merge($files, $parsed['files']);
      $directories = array_merge($directories, $parsed['directories']);
      $marker = $parsed['next_marker'] !== '' ? $parsed['next_marker'] : null;
    } while ($marker !== null);

    return ['files' => $files, 'directories' => $directories];
  }

  /**
   * Returns validated config values.
   *
   * @return array{account_name:string,account_key:string,share_name:string,directory_path:string,endpoint:string}
   *
   * @throws \RuntimeException if required settings are missing.
   */
  private function getConfig(): array {
    $cfg = $this->configFactory->get('azure_storage_browser.settings');

    $account = (string) $cfg->get('azure_account_name');
    $key     = (string) $cfg->get('azure_account_key');
    $share   = (string) $cfg->get('azure_share_name');

    if ($account === '' || $key === '' || $share === '') {
      throw new \RuntimeException(
        'Azure File Share Browser is not fully configured. '
        . 'Please set the account name, account key, and file share name.'
      );
    }

    return [
      'account_name'   => $account,
      'account_key'    => $key,
      'share_name'     => $share,
      'directory_path' => (string) ($cfg->get('azure_directory_path') ?? ''),
      // Local development only: points the service at the mock server in
      // tools/azure-storage-mock. Empty means the production endpoint.
      'endpoint'       => (string) ($cfg->get('azure_file_endpoint') ?? ''),
    ];
  }

  /**
   * Parses the "List Directories and Files" XML response.
   *
   * @return array{files: list<array{name:string,size:int,last_modified:string}>, directories: list<string>, next_marker: string}
   */
  private function parseListFilesXml(string $xml): array {
    $prev = libxml_use_internal_errors(true);
    $doc  = simplexml_load_string($xml);
    libxml_use_internal_errors($prev);

    if ($doc === false) {
      throw new \RuntimeException('Failed to parse Azure List Files response as XML.');
    }

    $files = [];
    foreach ($doc->Entries->File ?? [] as $file) {
      // Service versions >= 2017-04-17 nest size/dates under <Properties>.
      $size = 0;
      $lastModified = '';
      if (isset($file->Properties)) {
        $size = (int) $file->Properties->{'Content-Length'};
        $lastModified = (string) $file->Properties->{'Last-Modified'};
      }
      elseif (isset($file->Size)) {
        $size = (int) $file->Size;
      }

      $files[] = [
        'name'          => (string) $file->Name,
        'size'          => $size,
        'last_modified' => $lastModified,
      ];
    }

    $directories = [];
    foreach ($doc->Entries->Directory ?? [] as $directory) {
      $directories[] = (string) $directory->Name;
    }

    $nextMarker = (string) ($doc->NextMarker ?? '');

    return [
      'files'       => $files,
      'directories' => $directories,
      'next_marker' => $nextMarker,
    ];
  }

}
