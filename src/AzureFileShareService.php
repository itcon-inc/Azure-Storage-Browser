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
final class AzureFileShareService {

  use StringTranslationTrait;
  use AzureRestClientTrait;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  // ---------------------------------------------------------------------------
  // Public API
  // ---------------------------------------------------------------------------

  /**
   * Returns a flattened list of file metadata from the configured share.
   *
   * Each item contains:
   *   - name          (string) full path within the share, e.g. "backups/db.sql"
   *   - size           (int)    content length in bytes
   *   - last_modified  (string) RFC 1123 date string
   *
   * @return list<array{name:string,size:int,last_modified:string}>
   *
   * @throws \RuntimeException on HTTP or XML parse errors.
   */
  public function listFiles(): array {
    $config = $this->getConfig();
    $rootDirectory = trim($config['directory_path'], '/');

    return $this->listDirectoryRecursive($config, $rootDirectory);
  }

  /**
   * Downloads a file's content, streaming it directly to $destination.
   *
   * @param string $filePath
   *   The full file path within the share, as returned by listFiles().
   * @param resource $destination
   *   A writable stream, e.g. fopen('php://output', 'wb').
   *
   * @return array{content_type: string, content_length: ?int}
   *   Metadata about the downloaded file, taken from Azure's response
   *   headers.
   *
   * @throws \RuntimeException on HTTP or cURL errors.
   */
  public function downloadFile(string $filePath, $destination): array {
    $config  = $this->getConfig();
    $account = $config['account_name'];
    $share   = $config['share_name'];

    $encodedPath = implode('/', array_map('rawurlencode', explode('/', $filePath)));
    $resourcePath = '/' . $share . '/' . ltrim($filePath, '/');

    $url = sprintf(
      'https://%s.file.core.usgovcloudapi.net/%s/%s',
      rawurlencode($account),
      rawurlencode($share),
      $encodedPath
    );

    $date = $this->utcDate();
    $headers = [
      'x-ms-date'    => $date,
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
   * Validates that the required settings are present, without making any
   * network calls. Lets callers fail fast (e.g. redirect with a friendly
   * message) before committing to a streamed HTTP response.
   *
   * @throws \RuntimeException if required settings are missing.
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
   * @param array{account_name:string,account_key:string,share_name:string,directory_path:string,sas_expiry_minutes:int} $config
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
   * @param array{account_name:string,account_key:string,share_name:string,directory_path:string,sas_expiry_minutes:int} $config
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

      $url = sprintf(
        'https://%s.file.core.usgovcloudapi.net%s?%s',
        rawurlencode($account),
        $this->encodePath($resourcePath),
        http_build_query($queryParams)
      );

      $date = $this->utcDate();
      $headers = [
        'x-ms-date'    => $date,
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
   * @return array{account_name:string,account_key:string,share_name:string,directory_path:string,sas_expiry_minutes:int}
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
      'account_name'       => $account,
      'account_key'        => $key,
      'share_name'         => $share,
      'directory_path'     => (string) ($cfg->get('azure_directory_path') ?? ''),
      'sas_expiry_minutes' => (int)    ($cfg->get('sas_token_expiry_minutes') ?? 60),
    ];
  }

  /**
   * URL-encodes each segment of a resource path while preserving the slashes.
   */
  private function encodePath(string $path): string {
    return implode('/', array_map('rawurlencode', explode('/', $path)));
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
