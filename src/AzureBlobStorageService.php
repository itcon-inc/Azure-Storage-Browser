<?php

declare(strict_types=1);

namespace Drupal\azure_storage_browser;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Service for interacting with Azure Blob Storage via REST API.
 *
 * This implementation uses the Azure Blob Service REST API directly with
 * HMAC-SHA256 Shared Key authentication, requiring no external SDK.
 */
final class AzureBlobStorageService {

  use StringTranslationTrait;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  // ---------------------------------------------------------------------------
  // Public API
  // ---------------------------------------------------------------------------

  /**
   * Returns a list of blob metadata from the configured container.
   *
   * Each item contains:
   *   - name   (string)  blob name
   *   - size   (int)     content length in bytes
   *   - last_modified (string) RFC 1123 date string
   *   - content_type (string)
   *
   * @return list<array{name:string,size:int,last_modified:string,content_type:string}>
   *
   * @throws \RuntimeException on HTTP or XML parse errors.
   */
  public function listBlobs(): array {
    $config  = $this->getConfig();
    $account = $config['account_name'];
    $container = $config['container_name'];
    $prefix  = $config['blob_prefix'];

    $queryParams = [
      'restype' => 'container',
      'comp'    => 'list',
      'maxresults' => '5000',
    ];
    if ($prefix !== '') {
      $queryParams['prefix'] = $prefix;
    }

    $url = sprintf(
      'https://%s.file.core.usgovcloudapi.net/%s?%s',
      rawurlencode($account),
      rawurlencode($container),
      http_build_query($queryParams)
    );

    $date = $this->utcDate();
    $headers = [
      'x-ms-date'    => $date,
      'x-ms-version' => '2022-04-01',
    ];

    $canonicalisedHeaders = $this->canonicaliseHeaders($headers);
    $canonicalisedResource = $this->canonicaliseResource($account, '/' . $container, $queryParams);

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
    return $this->parseListBlobsXml($response);
  }

  /**
   * Generates a time-limited SAS download URL for a named blob.
   *
   * The URL is signed with the account key and grants read-only access for the
   * configured number of minutes.
   *
   * @param string $blobName
   *   The full blob name as returned by listBlobs().
   *
   * @return string
   *   An HTTPS URL the client can use to download the blob directly.
   */
  public function generateSasUrl(string $blobName): string {
    $config    = $this->getConfig();
    $account   = $config['account_name'];
    $container = $config['container_name'];
    $key       = $config['account_key'];
    $minutes   = $config['sas_expiry_minutes'];

    $start  = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $expiry = $start->modify("+{$minutes} minutes");

    $startStr  = $start->format('Y-m-d\TH:i:s\Z');
    $expiryStr = $expiry->format('Y-m-d\TH:i:s\Z');

    // Signed string for Blob Service SAS (service version 2020-10-02).
    $signedPermissions = 'r';       // read-only
    $signedService     = 'b';       // blob
    $signedResource    = 'b';       // single blob
    $signedProtocol    = 'https';
    $signedVersion     = '2022-04-01';

    $stringToSign = implode("\n", [
      $signedPermissions,
      $startStr,
      $expiryStr,
      // Canonicalised resource: /blob/account/container/blob
      '/blob/' . $account . '/' . $container . '/' . ltrim($blobName, '/'),
      '',  // signedIdentifier
      '',  // signedIP
      $signedProtocol,
      $signedVersion,
      $signedResource,
      '',  // snapshot time
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

    return sprintf(
      'https://%s.file.core.usgovcloudapi.net/%s/%s?%s',
      rawurlencode($account),
      rawurlencode($container),
      implode('/', array_map('rawurlencode', explode('/', $blobName))),
      http_build_query($sasParams)
    );
  }

  // ---------------------------------------------------------------------------
  // Internal helpers
  // ---------------------------------------------------------------------------

  /**
   * Returns validated config values.
   *
   * @return array{account_name:string,account_key:string,container_name:string,blob_prefix:string,sas_expiry_minutes:int}
   *
   * @throws \RuntimeException if required settings are missing.
   */
  private function getConfig(): array {
    $cfg = $this->configFactory->get('azure_storage_browser.settings');

    $account   = (string) $cfg->get('azure_account_name');
    $key       = (string) $cfg->get('azure_account_key');
    $container = (string) $cfg->get('azure_container_name');

    if ($account === '' || $key === '' || $container === '') {
      throw new \RuntimeException(
        'Azure Storage Browser is not fully configured. '
        . 'Please set the account name, account key, and container name.'
      );
    }

    return [
      'account_name'      => $account,
      'account_key'       => $key,
      'container_name'    => $container,
      'blob_prefix'       => (string) ($cfg->get('azure_blob_prefix') ?? ''),
      'sas_expiry_minutes'=> (int)    ($cfg->get('sas_token_expiry_minutes') ?? 60),
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
      // Try to extract Azure's error message from the XML body.
      $message = $this->extractAzureError((string) $body) ?? "HTTP {$status}";
      throw new \RuntimeException("Azure Blob Storage returned an error: {$message}");
    }

    return (string) $body;
  }

  /**
   * Parses the List Blobs XML response into a structured array.
   *
   * @return list<array{name:string,size:int,last_modified:string,content_type:string}>
   */
  private function parseListBlobsXml(string $xml): array {
    $prev = libxml_use_internal_errors(true);
    $doc  = simplexml_load_string($xml);
    libxml_use_internal_errors($prev);

    if ($doc === false) {
      throw new \RuntimeException('Failed to parse Azure List Blobs response as XML.');
    }

    $blobs = [];
    foreach ($doc->Blobs->Blob ?? [] as $blob) {
      $blobs[] = [
        'name'          => (string) $blob->Name,
        'size'          => (int)    $blob->Properties->{'Content-Length'},
        'last_modified' => (string) $blob->Properties->{'Last-Modified'},
        'content_type'  => (string) $blob->Properties->{'Content-Type'},
      ];
    }
    return $blobs;
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
