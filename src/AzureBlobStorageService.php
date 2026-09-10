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
 *
 * Downloads are proxied through this service (rather than redirecting the
 * client to a SAS URL) so that only the Drupal server needs network access
 * to the storage account — useful when the account's firewall restricts
 * access to specific IP ranges that don't include end-user browsers.
 */
final class AzureBlobStorageService implements AzureStorageBackendInterface {

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
   * Blob names are already full paths, so no traversal is needed — but a
   * container with more blobs than `maxresults` is returned one page at a
   * time, so the NextMarker continuation must be followed or the listing is
   * silently truncated.
   *
   * Items also carry a `content_type` key, which callers may ignore.
   *
   * @return list<array{name:string,size:int,last_modified:string,content_type:string}>
   */
  public function listFiles(): array {
    $config    = $this->getConfig();
    $account   = $config['account_name'];
    $container = $config['container_name'];
    $prefix    = $config['blob_prefix'];

    $blobs  = [];
    $marker = NULL;

    do {
      $queryParams = [
        'restype'    => 'container',
        'comp'       => 'list',
        'maxresults' => '5000',
      ];
      if ($prefix !== '') {
        $queryParams['prefix'] = $prefix;
      }
      if ($marker !== NULL) {
        $queryParams['marker'] = $marker;
      }

      $url = $this->buildUrl($account, 'blob', '/' . $container, $queryParams, $config['endpoint']);

      $date = $this->utcDate();
      $headers = [
        'x-ms-date'    => $date,
        // 2020-10-02 is a real Storage *data plane* service version. The
        // previous value here, 2022-04-01, is an ARM management-plane version
        // (Microsoft.Storage/storageAccounts@2022-04-01) and is rejected by
        // the data plane with InvalidHeaderValue — the blob backend never
        // worked. Verified against the real account 2026-09-09.
        'x-ms-version' => '2020-10-02',
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

      $parsed = $this->parseListBlobsXml($this->httpGet($url, $headers));

      $blobs  = array_merge($blobs, $parsed['blobs']);
      $marker = $parsed['next_marker'] !== '' ? $parsed['next_marker'] : NULL;
    } while ($marker !== NULL);

    return $blobs;
  }

  /**
   * {@inheritdoc}
   */
  public function downloadFile(string $path, $destination): array {
    $config    = $this->getConfig();
    $account   = $config['account_name'];
    $container = $config['container_name'];

    $url = $this->buildUrl($account, 'blob', '/' . $container . '/' . $path, [], $config['endpoint']);

    $date = $this->utcDate();
    $headers = [
      'x-ms-date'    => $date,
      // See the note in listFiles(): 2022-04-01 is an ARM version, not a
      // data-plane one.
      'x-ms-version' => '2020-10-02',
    ];

    $canonicalisedHeaders = $this->canonicaliseHeaders($headers);
    $canonicalisedResource = $this->canonicaliseResource($account, '/' . $container . '/' . $path);

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
   * Returns validated config values.
   *
   * @return array{account_name:string,account_key:string,container_name:string,blob_prefix:string,endpoint:string}
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
      'account_name'   => $account,
      'account_key'    => $key,
      'container_name' => $container,
      'blob_prefix'    => (string) ($cfg->get('azure_blob_prefix') ?? ''),
      // Local development only: points the service at the mock server in
      // tools/azure-storage-mock. Empty means the production endpoint.
      'endpoint'       => (string) ($cfg->get('azure_blob_endpoint') ?? ''),
    ];
  }

  /**
   * Parses the List Blobs XML response.
   *
   * @return array{blobs: list<array{name:string,size:int,last_modified:string,content_type:string}>, next_marker: string}
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
    return [
      'blobs'       => $blobs,
      'next_marker' => (string) ($doc->NextMarker ?? ''),
    ];
  }

}
