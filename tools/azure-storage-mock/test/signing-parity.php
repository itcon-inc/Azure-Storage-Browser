<?php

/**
 * Cross-validates the module's real Shared Key signing against the mock's
 * independent, spec-derived implementation.
 *
 * This requires the module's actual AzureRestClientTrait — not a copy — so a
 * pass means the module's canonicalisation agrees with the Microsoft spec as
 * implemented separately in lib/sharedkey.js. The trait has no Drupal
 * dependencies, so it loads standalone.
 */

declare(strict_types=1);

const MODULE_TRAIT = __DIR__ . '/../../../src/AzureRestClientTrait.php';

if (!is_file(MODULE_TRAIT)) {
  fwrite(STDERR, "Cannot find the module trait at " . MODULE_TRAIT . "\n");
  exit(2);
}

require_once MODULE_TRAIT;

/** Exposes the module's private signing helpers for testing. */
final class ModuleSigner {
  use \Drupal\azure_storage_browser\AzureRestClientTrait;

  public function sign(string $account, string $key, string $verb, string $resourcePath, array $queryParams, string $version): array {
    $date = $this->utcDate();
    $headers = [
      'x-ms-date'    => $date,
      'x-ms-version' => $version,
    ];

    $stringToSign = implode("\n", [
      $verb, '', '', '', '', '', '', '', '', '', '', '',
      $this->canonicaliseHeaders($headers),
      $this->canonicaliseResource($account, $resourcePath, $queryParams),
    ]);

    $headers['Authorization'] = $this->buildSharedKeyAuth($account, $key, $stringToSign);
    return [$headers, $stringToSign];
  }

  public function get(string $url, array $headers): string {
    return $this->httpGet($url, $headers);
  }

  public function getStream(string $url, array $headers, $destination): array {
    return $this->httpGetStream($url, $headers, $destination);
  }

  public function url(string $account, string $service, string $path, array $queryParams = [], string $endpoint = ''): string {
    return $this->buildUrl($account, $service, $path, $queryParams, $endpoint);
  }
}

$account = 'devstoreaccount1';
$key     = 'Eby8vdM02xNOcqFlqUwJPLlmEtlCDXJ1OUzFT50uSRZ6IFsuFq2UVErCz4I6tq/K1SZFPTOtr/KBHBeksoGMGw==';
$share   = 'va-prd-nesr-db';
$version = '2020-10-02';
$fileBase = 'http://localhost:10001';
$blobBase = 'http://localhost:10002';

$signer = new ModuleSigner();
$passed = 0;
$failed = 0;

function report(bool $ok, string $label, string $detail = ''): void {
  global $passed, $failed;
  if ($ok) {
    $passed++;
    fwrite(STDOUT, "  \033[32mPASS\033[0m  {$label}\n");
  } else {
    $failed++;
    fwrite(STDOUT, "  \033[31mFAIL\033[0m  {$label}\n");
    if ($detail !== '') {
      fwrite(STDOUT, "        {$detail}\n");
    }
  }
}

// ---------------------------------------------------------------------------
fwrite(STDOUT, "\nList a directory (Files service)\n");
// ---------------------------------------------------------------------------

$listDir = function (string $directoryPath, array $extraParams = []) use ($signer, $account, $key, $share, $version, $fileBase): array {
  $queryParams = array_merge([
    'restype'    => 'directory',
    'comp'       => 'list',
    'maxresults' => '5000',
  ], $extraParams);

  $resourcePath = '/' . $share . ($directoryPath !== '' ? '/' . $directoryPath : '');

  $url = $signer->url($account, 'file', $resourcePath, $queryParams, $fileBase);

  [$headers] = $signer->sign($account, $key, 'GET', $resourcePath, $queryParams, $version);
  return [$signer->get($url, $headers), $url];
};

try {
  [$xml] = $listDir('');
  $doc = simplexml_load_string($xml);
  $names = [];
  foreach ($doc->Entries->File ?? [] as $f) { $names[] = (string) $f->Name; }
  $dirs = [];
  foreach ($doc->Entries->Directory ?? [] as $d) { $dirs[] = (string) $d->Name; }

  report($doc !== false, 'share root listing parses as XML');
  report(in_array('readme.txt', $names, true), 'root file listed', implode(', ', $names));
  report(in_array('backups', $dirs, true), 'subdirectory listed', implode(', ', $dirs));

  $readme = null;
  foreach ($doc->Entries->File ?? [] as $f) {
    if ((string) $f->Name === 'readme.txt') { $readme = $f; }
  }
  report($readme !== null && (int) $readme->Properties->{'Content-Length'} > 0, 'Content-Length populated under Properties');
  report($readme !== null && (string) $readme->Properties->{'Last-Modified'} !== '', 'Last-Modified populated under Properties');
}
catch (\RuntimeException $e) {
  report(false, 'share root listing', $e->getMessage());
}

// Nested directory, three levels deep.
try {
  [$xml] = $listDir('backups/archive/2025/q4');
  $doc = simplexml_load_string($xml);
  $names = [];
  foreach ($doc->Entries->File ?? [] as $f) { $names[] = (string) $f->Name; }
  report(in_array('nesr-2025-12-31.sql.gz', $names, true), 'deeply nested directory listing', implode(', ', $names));
}
catch (\RuntimeException $e) {
  report(false, 'deeply nested directory listing', $e->getMessage());
}

// Directory whose listing needs a continuation marker.
try {
  [$xml] = $listDir('awkward', ['maxresults' => '3']);
  $doc = simplexml_load_string($xml);
  $marker = (string) $doc->NextMarker;
  report($marker !== '', 'NextMarker returned when maxresults truncates');

  [$xml2] = $listDir('awkward', ['maxresults' => '3', 'marker' => $marker]);
  $doc2 = simplexml_load_string($xml2);
  $firstPage = [];
  foreach ($doc->Entries->File ?? [] as $f) { $firstPage[] = (string) $f->Name; }
  $secondPage = [];
  foreach ($doc2->Entries->File ?? [] as $f) { $secondPage[] = (string) $f->Name; }
  report(
    $secondPage !== [] && array_intersect($firstPage, $secondPage) === [],
    'continuation returns a distinct second page',
    'page1=' . implode(',', $firstPage) . ' page2=' . implode(',', $secondPage)
  );
}
catch (\RuntimeException $e) {
  report(false, 'marker continuation', $e->getMessage());
}

// ---------------------------------------------------------------------------
fwrite(STDOUT, "\nDownload files with awkward names (Files service)\n");
// ---------------------------------------------------------------------------

$download = function (string $filePath) use ($signer, $account, $key, $share, $version, $fileBase): array {
  $resourcePath = '/' . $share . '/' . ltrim($filePath, '/');
  $url = $signer->url($account, 'file', $resourcePath, [], $fileBase);

  [$headers] = $signer->sign($account, $key, 'GET', $resourcePath, [], $version);

  $tmp = fopen('php://temp', 'w+b');
  $meta = $signer->getStream($url, $headers, $tmp);
  rewind($tmp);
  $body = stream_get_contents($tmp);
  fclose($tmp);
  return [$body, $meta];
};

$awkward = [
  'readme.txt',
  'backups/daily/nesr-2026-09-08.sql.gz',
  'backups/archive/2025/q4/nesr-2025-12-31.sql.gz',
  'awkward/file with spaces.sql',
  'awkward/plus+sign.sql',
  'awkward/amp&ersand.sql',
  'awkward/hash#symbol.sql',
  'awkward/percent%25encoded.sql',
  "awkward/apostrophe's.sql",
  'awkward/unicode-café-日本語.sql',
  'awkward/equals=sign.sql',
  'awkward/empty.sql',
];

foreach ($awkward as $filePath) {
  try {
    [$body, $meta] = $download($filePath);
    $expectEmpty = str_ends_with($filePath, 'empty.sql');
    $ok = $expectEmpty ? $body === '' : $body !== '';
    report($ok, "download: {$filePath}", 'got ' . strlen($body) . ' bytes, type ' . $meta['content_type']);
  }
  catch (\RuntimeException $e) {
    report(false, "download: {$filePath}", $e->getMessage());
  }
}

// Streaming: the large file must arrive whole.
try {
  [$body, $meta] = $download('large/streaming-test.bin');
  $expected = 8 * 1024 * 1024;
  report(
    strlen($body) === $expected,
    'download: large/streaming-test.bin streams fully',
    'got ' . strlen($body) . ' of ' . $expected . ' bytes'
  );
  report(
    $meta['content_length'] === $expected,
    'Content-Length header round-trips through httpGetStream()',
    var_export($meta['content_length'], true)
  );
}
catch (\RuntimeException $e) {
  report(false, 'large file download', $e->getMessage());
}

// ---------------------------------------------------------------------------
fwrite(STDOUT, "\nError paths\n");
// ---------------------------------------------------------------------------

try {
  $download('does/not/exist.sql');
  report(false, 'missing file raises RuntimeException', 'no exception thrown');
}
catch (\RuntimeException $e) {
  report(
    str_contains($e->getMessage(), 'ResourceNotFound'),
    'missing file surfaces Azure error code via extractAzureError()',
    $e->getMessage()
  );
}

try {
  $resourcePath = '/' . $share;
  $queryParams = ['restype' => 'directory', 'comp' => 'list', 'maxresults' => '5000'];
  $url = $signer->url($account, 'file', $resourcePath, $queryParams, $fileBase);
  [$headers] = $signer->sign($account, 'd3Jvbmcta2V5LWVudGlyZWx5LWJ1dC12YWxpZC1iYXNlNjQ=', 'GET', $resourcePath, $queryParams, $version);
  $signer->get($url, $headers);
  report(false, 'wrong key is rejected', 'no exception thrown');
}
catch (\RuntimeException $e) {
  report(
    str_contains($e->getMessage(), 'AuthenticationFailed'),
    'wrong account key surfaces AuthenticationFailed',
    $e->getMessage()
  );
}

// The mock must reject bogus service versions the way real Azure does. This
// exists because it previously did not: the module shipped an ARM
// management-plane version (2022-04-01) that Azure refuses with
// InvalidHeaderValue, and every local test passed regardless.
try {
  $queryParams = ['restype' => 'directory', 'comp' => 'list'];
  $resourcePath = '/' . $share;
  $url = $signer->url($account, 'file', $resourcePath, $queryParams, $fileBase);
  [$headers] = $signer->sign($account, $key, 'GET', $resourcePath, $queryParams, '2022-04-01');
  $signer->get($url, $headers);
  report(false, 'ARM API version is rejected', 'request unexpectedly succeeded');
}
catch (\RuntimeException $e) {
  report(
    str_contains($e->getMessage(), 'InvalidHeaderValue'),
    'ARM API version (2022-04-01) is rejected as InvalidHeaderValue',
    $e->getMessage()
  );
}

// ---------------------------------------------------------------------------
fwrite(STDOUT, "\nBlob service\n");
// ---------------------------------------------------------------------------

try {
  $container = 'va-prd-nesr-db';
  $queryParams = ['restype' => 'container', 'comp' => 'list', 'maxresults' => '5000'];
  $url = $signer->url($account, 'blob', '/' . $container, $queryParams, $blobBase);
  [$headers] = $signer->sign($account, $key, 'GET', '/' . $container, $queryParams, $version);
  $xml = $signer->get($url, $headers);
  $doc = simplexml_load_string($xml);

  $blobs = [];
  foreach ($doc->Blobs->Blob ?? [] as $b) { $blobs[] = (string) $b->Name; }

  report($doc !== false, 'container listing parses as XML');
  report(in_array('backups/daily/nesr-2026-09-08.sql.gz', $blobs, true), 'blob names are flattened paths', (string) count($blobs) . ' blobs');

  $first = $doc->Blobs->Blob[0] ?? null;
  report($first !== null && (string) $first->Properties->{'Content-Type'} !== '', 'blob Content-Type populated');
}
catch (\RuntimeException $e) {
  report(false, 'container listing', $e->getMessage());
}

// ---------------------------------------------------------------------------
fwrite(STDOUT, "\n" . str_repeat('-', 60) . "\n");
fwrite(STDOUT, sprintf("%d passed, %d failed\n\n", $passed, $failed));
exit($failed === 0 ? 0 : 1);
