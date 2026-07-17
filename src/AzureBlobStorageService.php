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
final class AzureBlobStorageService {

  use StringTranslationTrait;
  use AzureRestClientTrait;

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
      'https://%s.blob.core.usgovcloudapi.net/%s?%s',
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
   * Downloads a blob's content, streaming it directly to $destination.
   *
   * @param string $blobName
   *   The full blob name as returned by listBlobs().
   * @param resource $destination
   *   A writable stream, e.g. fopen('php://output', 'wb').
   *
   * @return array{content_type: string, content_length: ?int}
   *   Metadata about the downloaded blob, taken from Azure's response
   *   headers.
   *
   * @throws \RuntimeException on HTTP or cURL errors.
   */
  public function downloadBlob(string $blobName, $destination): array {
    $config    = $this->getConfig();
    $account   = $config['account_name'];
    $container = $config['container_name'];

    $encodedPath = implode('/', array_map('rawurlencode', explode('/', $blobName)));

    $url = sprintf(
      'https://%s.blob.core.usgovcloudapi.net/%s/%s',
      rawurlencode($account),
      rawurlencode($container),
      $encodedPath
    );

    $date = $this->utcDate();
    $headers = [
      'x-ms-date'    => $date,
      'x-ms-version' => '2022-04-01',
    ];

    $canonicalisedHeaders = $this->canonicaliseHeaders($headers);
    $canonicalisedResource = $this->canonicaliseResource($account, '/' . $container . '/' . $blobName);

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

}
