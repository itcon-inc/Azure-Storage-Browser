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
 */
final class AzureFileShareService {

  use StringTranslationTrait;

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
   * Generates a time-limited SAS download URL for a named file.
   *
   * The URL is signed with the account key and grants read-only access for the
   * configured number of minutes.
   *
   * @param string $filePath
   *   The full file path within the share, as returned by listFiles().
   *
   * @return string
   *   An HTTPS URL the client can use to download the file directly.
   */
  public function generateSasUrl(string $filePath): string {
    $config  = $this->getConfig();
    $account = $config['account_name'];
    $share   = $config['share_name'];
    $key     = $config['account_key'];
    $minutes = $config['sas_expiry_minutes'];

    $start  = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $expiry = $start->modify("+{$minutes} minutes");

    $startStr  = $start->format('Y-m-d\TH:i:s\Z');
    $expiryStr = $expiry->format('Y-m-d\TH:i:s\Z');

    // Signed string for File Service SAS (service version 2020-10-02).
    $signedPermissions = 'r';       // read-only
    $signedResource    = 'f';       // single file ('s' would be the whole share)
    $signedProtocol    = 'https';
    $signedVersion     = '2020-10-02';

    $canonicalPath = '/file/' . $account . '/' . $share . '/' . ltrim($filePath, '/');

    $stringToSign = implode("\n", [
      $signedPermissions,
      $startStr,
      $expiryStr,
      $canonicalPath,
      '',  // signedIdentifier
      '',  // signedIP
      $signedProtocol,
      $signedVersion,
      $signedResource,
      '',  // snapshot time (not applicable to files)
      '',  // encryption scope
      '',  // rscc (Cache-Control override)
      '',  // rscd (Content-Disposition override)
      '',  // rsce (Content-Encoding override)
      '',  // rscl (Content-Language override)
      '',  // rsct (Content-Type override)
    ]);

    $sig = base64_encode(hash_hmac('sha256', $stringToSign, base64_decode($key), true));

    $sasParams = [
      'sv'  => $signedVersion,
      'st'  => $startStr,
      'se'  => $expiryStr,
      'sr'  => $signedResource,
      'sp'  => $signedPermissions,
      'spr' => $signedProtocol,
      'sig' => $sig,
    ];

    $encodedPath = implode('/', array_map('rawurlencode', explode('/', $filePath)));

    return sprintf(
      'https://%s.file.core.usgovcloudapi.net/%s/%s?%s',
      rawurlencode($account),
      rawurlencode($share),
      $encodedPath,
      http_build_query($sasParams)
    );
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
   * Builds the Authorization header value for Shared Key authentication.
   */
  private function buildSharedKeyAuth(string $account, string $key, string $stringToSign): string {
    $sig = base64_encode(hash_hmac('sha256', $stringToSign, base64_decode($key), true));
    return "SharedKey {$account}:{$sig}";
  }

  /**
   * Produces the canonicalised headers string required by Shared Key auth.
   *
   * @param array<string, string> $headers
   */
  private function canonicaliseHeaders(array $headers): string {
    $msHeaders = [];
    foreach ($headers as $name => $value) {
      $lower = strtolower($name);
      if (str_starts_with($lower, 'x-ms-')) {
        $msHeaders[$lower] = trim($value);
      }
    }
    ksort($msHeaders);
    $lines = [];
    foreach ($msHeaders as $name => $value) {
      $lines[] = "{$name}:{$value}";
    }
    return implode("\n", $lines);
  }

  /**
   * Produces the canonicalised resource string for Shared Key auth.
   *
   * @param array<string, string> $queryParams
   */
  private function canonicaliseResource(string $account, string $path, array $queryParams): string {
    $resource = "/{$account}{$path}";
    ksort($queryParams);
    foreach ($queryParams as $k => $v) {
      $resource .= "\n" . strtolower($k) . ':' . $v;
    }
    return $resource;
  }

  /**
   * URL-encodes each segment of a resource path while preserving the slashes.
   */
  private function encodePath(string $path): string {
    return implode('/', array_map('rawurlencode', explode('/', $path)));
  }

  /**
   * Returns the current UTC date in the RFC 1123 format Azure requires.
   */
  private function utcDate(): string {
    return gmdate('D, d M Y H:i:s') . ' GMT';
  }

  /**
   * Performs an HTTP GET request and returns the response body.
   *
   * @param array<string, string> $headers
   *
   * @throws \RuntimeException on cURL or HTTP errors.
   */
  private function httpGet(string $url, array $headers): string {
    $curlHeaders = [];
    foreach ($headers as $name => $value) {
      $curlHeaders[] = "{$name}: {$value}";
    }

    $ch = curl_init($url);
    if ($ch === false) {
      throw new \RuntimeException('Failed to initialise cURL.');
    }

    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER     => $curlHeaders,
      CURLOPT_TIMEOUT        => 30,
      CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error  = curl_error($ch);
    curl_close($ch);

    if ($body === false || $error !== '') {
      throw new \RuntimeException("cURL error contacting Azure: {$error}");
    }

    if ($status < 200 || $status >= 300) {
      $message = $this->extractAzureError((string) $body) ?? "HTTP {$status}";
      throw new \RuntimeException("Azure Files returned an error: {$message}");
    }

    return (string) $body;
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

  /**
   * Attempts to extract the human-readable message from an Azure error response.
   */
  private function extractAzureError(string $xml): ?string {
    $prev = libxml_use_internal_errors(true);
    $doc  = simplexml_load_string($xml);
    libxml_use_internal_errors($prev);
    if ($doc === false) {
      return null;
    }
    $message = (string) ($doc->Message ?? '');
    $code    = (string) ($doc->Code ?? '');
    if ($code !== '' && $message !== '') {
      return "{$code} – " . trim($message);
    }
    return $message !== '' ? trim($message) : null;
  }

}
