<?php

declare(strict_types=1);

namespace Drupal\azure_storage_browser\Controller;

use Drupal\azure_storage_browser\AzureStorageBackendResolver;
use Drupal\azure_storage_browser\AzureStorageDisplayHelpersTrait;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Browse and download pages for the configured Azure storage backend.
 *
 * Backend-agnostic: it talks to whichever of Blob Storage or Azure Files the
 * `storage_backend` setting selects, via the resolver. Both present the same
 * flat list of files, so one controller serves either.
 */
class AzureStorageBrowserController extends ControllerBase {

  use AzureStorageDisplayHelpersTrait;

  public function __construct(
    private readonly AzureStorageBackendResolver $backend,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('azure_storage_browser.backend'),
      $container->get('logger.channel.azure_storage_browser'),
    );
  }

  /**
   * Returns the settings for this module.
   */
  private function settings(): ImmutableConfig {
    // ControllerBase::config() resolves through config.factory, so settings.php
    // overrides apply.
    return $this->config('azure_storage_browser.settings');
  }

  // ---------------------------------------------------------------------------
  // Routes
  // ---------------------------------------------------------------------------

  /**
   * Title callback, so the configured page title is actually used.
   */
  public function title(): string {
    $title = trim((string) ($this->settings()->get('page_title') ?? ''));
    return $title !== '' ? $title : (string) $this->t('Available Files');
  }

  /**
   * Renders the file listing page.
   */
  public function listFiles(): array {
    $config            = $this->settings();
    $showSize          = (bool) $config->get('show_file_size');
    $showModified      = (bool) $config->get('show_last_modified');
    $allowedExtensions = $this->parseExtensions((string) ($config->get('allowed_extensions') ?? ''));

    // Surface configuration and connectivity errors as a message rather than a
    // white screen — this is only a browse page.
    try {
      $files = $this->backend->get()->listFiles();
    }
    catch (\RuntimeException $e) {
      $this->messenger()->addError($this->t(
        'Could not retrieve files from Azure: @message',
        ['@message' => $e->getMessage()]
      ));
      return ['#markup' => ''];
    }

    if ($allowedExtensions !== []) {
      $files = array_values(array_filter(
        $files,
        fn(array $f) => in_array($this->fileExtension($f['name']), $allowedExtensions, true)
      ));
    }

    if ($files === []) {
      return ['#markup' => $this->t('No files are currently available.')];
    }

    $header = [$this->t('File Name')];
    if ($showSize) {
      $header[] = $this->t('Size');
    }
    if ($showModified) {
      $header[] = $this->t('Last Modified');
    }
    $header[] = $this->t('Action');

    $rows = [];
    foreach ($files as $file) {
      // Display the filename, but route on the full path so files in
      // subdirectories resolve correctly.
      $row = [['data' => $this->formatDisplayName($file['name'])]];

      if ($showSize) {
        $row[] = ['data' => $this->formatBytes($file['size'])];
      }
      if ($showModified) {
        $row[] = ['data' => $this->formatDate($file['last_modified'])];
      }

      $row[] = [
        'data' => [
          '#type'  => 'link',
          '#title' => $this->t('Download'),
          '#url'   => Url::fromRoute(
            'azure_storage_browser.download',
            ['file' => base64_encode($file['name'])],
            ['absolute' => FALSE]
          ),
          '#attributes' => ['class' => ['button', 'button--small']],
        ],
      ];

      $rows[] = $row;
    }

    return [
      '#theme'      => 'table',
      '#header'     => $header,
      '#rows'       => $rows,
      '#attributes' => ['class' => ['azure-storage-browser__table']],
      '#empty'      => $this->t('No files found.'),
      '#attached'   => ['library' => ['azure_storage_browser/styles']],
      '#cache'      => [
        // Do not cache the listing; files change frequently.
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Streams a file's content back to the client through the Drupal server.
   *
   * The download is proxied rather than redirecting the browser to a SAS URL,
   * so only the Drupal server needs network access to the storage account —
   * important when the account's firewall is restricted to IP ranges that
   * don't (and can't reliably) include end-user browsers.
   *
   * The path is a base64-encoded route parameter so paths containing slashes
   * or special characters survive routing intact.
   */
  public function downloadFile(Request $request, string $file): Response {
    $path = base64_decode($file, strict: true);
    if ($path === false || $path === '') {
      throw new NotFoundHttpException();
    }

    // Re-check the extension server-side: the listing filter is not a control.
    $allowedExtensions = $this->parseExtensions((string) ($this->settings()->get('allowed_extensions') ?? ''));
    if ($allowedExtensions !== [] &&
        !in_array($this->fileExtension($path), $allowedExtensions, true)) {
      throw new AccessDeniedHttpException('This file type is not permitted for download.');
    }

    $backend = $this->backend->get();

    // Fail fast on the common misconfiguration case, before committing to a
    // streamed response whose headers can't be changed once sent.
    try {
      $backend->assertConfigured();
    }
    catch (\RuntimeException $e) {
      $this->messenger()->addError($this->t(
        'Could not download file: @message',
        ['@message' => $e->getMessage()]
      ));
      return $this->redirect('azure_storage_browser.list');
    }

    $logger = $this->logger;

    $response = new StreamedResponse(function () use ($backend, $path, $logger): void {
      $destination = fopen('php://output', 'wb');
      try {
        $backend->downloadFile($path, $destination);
      }
      catch (\RuntimeException $e) {
        // Headers are already committed by the time this callback runs, so a
        // clean error page isn't possible. Log it for admins and leave a short
        // message in the body.
        $logger->error('Download failed for @file: @message', [
          '@file'    => $path,
          '@message' => $e->getMessage(),
        ]);
        echo 'Download failed: ' . $e->getMessage();
      }
      finally {
        fclose($destination);
      }
    });

    $response->headers->set('Content-Type', 'application/octet-stream');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . addslashes(basename($path)) . '"');
    $response->headers->set('Cache-Control', 'no-store, no-cache');

    return $response;
  }

}
