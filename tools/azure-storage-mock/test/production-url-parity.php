<?php

/**
 * Guards the production URL form against the buildUrl() refactor.
 *
 * buildUrl() replaced four hand-rolled sprintf() calls. With no endpoint
 * override configured it must produce byte-identical URLs to the originals,
 * or the refactor silently breaks production while every local test passes.
 *
 * The "expected" values below are the literal originals, reproduced from
 * commit 77473b6cc.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../src/AzureRestClientTrait.php';

final class UrlBuilder {
  use \Drupal\azure_storage_browser\AzureRestClientTrait;

  public function url(string $account, string $service, string $path, array $q = [], string $endpoint = ''): string {
    return $this->buildUrl($account, $service, $path, $q, $endpoint);
  }
}

$b = new UrlBuilder();
$account = 'nesrvaprdsa';
$share = 'va-prd-nesr-db';
$container = 'va-prd-nesr-db';
$passed = 0;
$failed = 0;

function check(string $label, string $expected, string $actual): void {
  global $passed, $failed;
  if ($expected === $actual) {
    $passed++;
    fwrite(STDOUT, "  \033[32mPASS\033[0m  {$label}\n");
  } else {
    $failed++;
    fwrite(STDOUT, "  \033[31mFAIL\033[0m  {$label}\n");
    fwrite(STDOUT, "        expected: {$expected}\n");
    fwrite(STDOUT, "        actual:   {$actual}\n");
  }
}

fwrite(STDOUT, "\nProduction URL form (no endpoint override)\n");

// --- Files: list a directory ------------------------------------------------
$queryParams = ['restype' => 'directory', 'comp' => 'list', 'maxresults' => '5000'];
$resourcePath = '/' . $share . '/backups';

$expected = sprintf(
  'https://%s.file.core.usgovcloudapi.net%s?%s',
  rawurlencode($account),
  implode('/', array_map('rawurlencode', explode('/', $resourcePath))),
  http_build_query($queryParams)
);
check('Files: List Directories and Files', $expected, $b->url($account, 'file', $resourcePath, $queryParams));

// --- Files: download, including an awkward filename -------------------------
foreach (['backups/daily/db.sql.gz', 'awkward/file with spaces.sql', 'awkward/unicode-café.sql'] as $filePath) {
  $expected = sprintf(
    'https://%s.file.core.usgovcloudapi.net/%s/%s',
    rawurlencode($account),
    rawurlencode($share),
    implode('/', array_map('rawurlencode', explode('/', $filePath)))
  );
  check("Files: Get File — {$filePath}", $expected, $b->url($account, 'file', '/' . $share . '/' . $filePath));
}

// --- Blob: list a container -------------------------------------------------
$queryParams = ['restype' => 'container', 'comp' => 'list', 'maxresults' => '5000'];
$expected = sprintf(
  'https://%s.blob.core.usgovcloudapi.net/%s?%s',
  rawurlencode($account),
  rawurlencode($container),
  http_build_query($queryParams)
);
check('Blob: List Blobs', $expected, $b->url($account, 'blob', '/' . $container, $queryParams));

// --- Blob: download ---------------------------------------------------------
foreach (['backups/daily/db.sql.gz', 'awkward/file with spaces.sql'] as $blobName) {
  $expected = sprintf(
    'https://%s.blob.core.usgovcloudapi.net/%s/%s',
    rawurlencode($account),
    rawurlencode($container),
    implode('/', array_map('rawurlencode', explode('/', $blobName)))
  );
  check("Blob: Get Blob — {$blobName}", $expected, $b->url($account, 'blob', '/' . $container . '/' . $blobName));
}

fwrite(STDOUT, "\nLocal override form\n");

check(
  'Files: path-style override',
  'http://localhost:10001/devstoreaccount1/myshare/dir/file%20name.sql',
  $b->url('devstoreaccount1', 'file', '/myshare/dir/file name.sql', [], 'http://localhost:10001')
);
check(
  'trailing slash on the override is tolerated',
  'http://localhost:10002/devstoreaccount1/mycontainer',
  $b->url('devstoreaccount1', 'blob', '/mycontainer', [], 'http://localhost:10002/')
);

fwrite(STDOUT, "\n" . str_repeat('-', 60) . "\n");
fwrite(STDOUT, sprintf("%d passed, %d failed\n\n", $passed, $failed));
exit($failed === 0 ? 0 : 1);
