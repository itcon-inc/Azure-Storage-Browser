<?php

/**
 * Regression test for List Blobs continuation.
 *
 * AzureBlobStorageService::listFiles() originally issued exactly one request
 * with maxresults=5000 and ignored NextMarker, so a container with more than
 * 5000 blobs listed incompletely — no error, no indication anything was
 * missing. It now follows the marker.
 *
 * This asserts the continuation loop returns the complete container, and
 * reports what a single request would still have missed, so the cost of
 * regressing it stays visible.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../src/AzureRestClientTrait.php';

final class BlobLister {
  use \Drupal\azure_storage_browser\AzureRestClientTrait;

  public function __construct(
    private string $base,
    private string $account,
    private string $key,
    private string $container,
  ) {}

  private function request(array $queryParams): \SimpleXMLElement {
    $url = sprintf(
      '%s/%s/%s?%s',
      $this->base,
      rawurlencode($this->account),
      rawurlencode($this->container),
      http_build_query($queryParams)
    );

    $headers = [
      'x-ms-date'    => $this->utcDate(),
      'x-ms-version' => '2020-10-02',
    ];

    $stringToSign = implode("\n", [
      'GET', '', '', '', '', '', '', '', '', '', '', '',
      $this->canonicaliseHeaders($headers),
      $this->canonicaliseResource($this->account, '/' . $this->container, $queryParams),
    ]);

    $headers['Authorization'] = $this->buildSharedKeyAuth($this->account, $this->key, $stringToSign);

    return simplexml_load_string($this->httpGet($url, $headers));
  }

  /** The old single-request behaviour, kept to quantify what it missed. */
  public function listSingleRequest(): array {
    $doc = $this->request([
      'restype'    => 'container',
      'comp'       => 'list',
      'maxresults' => '5000',
    ]);

    $names = [];
    foreach ($doc->Blobs->Blob ?? [] as $blob) {
      $names[] = (string) $blob->Name;
    }
    return ['names' => $names, 'next_marker' => (string) ($doc->NextMarker ?? '')];
  }

  /** The marker-following loop the service now implements. */
  public function listComplete(): array {
    $names = [];
    $marker = null;
    $requests = 0;

    do {
      $queryParams = [
        'restype'    => 'container',
        'comp'       => 'list',
        'maxresults' => '5000',
      ];
      if ($marker !== null) {
        $queryParams['marker'] = $marker;
      }

      $doc = $this->request($queryParams);
      $requests++;

      foreach ($doc->Blobs->Blob ?? [] as $blob) {
        $names[] = (string) $blob->Name;
      }

      $next = (string) ($doc->NextMarker ?? '');
      $marker = $next !== '' ? $next : null;
    } while ($marker !== null);

    return ['names' => $names, 'requests' => $requests];
  }
}

$lister = new BlobLister(
  'http://localhost:10002',
  'devstoreaccount1',
  'Eby8vdM02xNOcqFlqUwJPLlmEtlCDXJ1OUzFT50uSRZ6IFsuFq2UVErCz4I6tq/K1SZFPTOtr/KBHBeksoGMGw==',
  'bulk'
);

$actual   = $lister->listSingleRequest();
$complete = $lister->listComplete();

$fixtureCount = (int) trim(shell_exec('find ' . escapeshellarg(__DIR__ . '/../fixtures/containers/bulk') . ' -type f | wc -l'));

printf("\nContainer \"bulk\" holds %d blobs on disk.\n\n", $fixtureCount);
printf("  Single request (the old bug)  : %d blobs, NextMarker %s\n",
  count($actual['names']),
  $actual['next_marker'] !== '' ? 'present and ignored' : '(none)');
printf("  Marker-following loop         : %d blobs in %d requests\n\n", count($complete['names']), $complete['requests']);

$missed = count($complete['names']) - count($actual['names']);

if (count($complete['names']) !== $fixtureCount) {
  printf("  \033[31mFAIL\033[0m  continuation returned %d of %d blobs.\n\n", count($complete['names']), $fixtureCount);
  exit(1);
}

printf("  \033[32mPASS\033[0m  continuation returns the complete container (%d blobs).\n", $fixtureCount);
if ($missed > 0) {
  printf("        Without it, %d blobs (%.1f%%) would be dropped silently — first missed: %s\n",
    $missed,
    100 * $missed / max(1, count($complete['names'])),
    $complete['names'][count($actual['names'])] ?? '(n/a)');
}
print "\n";
exit(0);
