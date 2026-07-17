<?php

declare(strict_types=1);

namespace Drupal\azure_storage_browser\Controller;

use Drupal\azure_storage_browser\AzureFileShareService;
use Drupal\azure_storage_browser\AzureStorageDisplayHelpersTrait;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for the Azure File Share browser pages.
 */
class AzureFileShareController extends ControllerBase {

  use AzureStorageDisplayHelpersTrait;

  public $azureService;
  public $settings;

  /**
   * Constructs an AzureFileShareController object.
   *
   * @param \Drupal\azure_storage_browser\AzureFileShareService $azureService
   *   The Azure File Share service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    AzureFileShareService $azureService,
    ConfigFactoryInterface $configFactory,
  ) {
    $this->azureService = $azureService;
    $this->settings = $configFactory->get('azure_storage_browser.settings');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('azure_storage_browser.file_share'),
      $container->get('config.factory'),
    );
  }

  // ---------------------------------------------------------------------------
  // Routes
  // ---------------------------------------------------------------------------

  /**
   * Renders the file listing page.
   */
  public function listFiles(): array {
    $config            = $this->settings;
    $showSize          = (bool) $config->get('show_file_size');
    $showModified      = (bool) $config->get('show_last_modified');
    $rawExtensions     = (string) ($config->get('allowed_extensions') ?? '');
    $allowedExtensions = $this->parseExtensions($rawExtensions);

    // Attempt to list files; surface configuration errors gracefully.
    try {
      $files = $this->azureService->listFiles();
    }
    catch (\RuntimeException $e) {
      $this->messenger()->addError($this->t(
        'Could not retrieve files from Azure: @message',
        ['@message' => $e->getMessage()]
      ));
      return ['#markup' => ''];
    }

    // Filter by allowed extensions if configured.
    if ($allowedExtensions !== []) {
      $files = array_values(array_filter(
        $files,
        fn(array $f) => in_array($this->fileExtension($f['name']), $allowedExtensions, true)
      ));
    }

    if ($files === []) {
      return [
        '#markup' => $this->t('No files are currently available.'),
      ];
    }

    // Build table header.
    $header = [$this->t('File Name')];
    if ($showSize) {
      $header[] = $this->t('Size');
    }
    if ($showModified) {
      $header[] = $this->t('Last Modified');
    }
    $header[] = $this->t('Action');

    // Build table rows.
    $rows = [];
    foreach ($files as $file) {
      $downloadUrl = Url::fromRoute(
        'azure_storage_browser.file_share.download',
        ['file' => base64_encode($file['name'])],
        ['absolute' => FALSE]
      );

      $row = [
        // Display just the filename portion, but use the full path for the
        // download route so subdirectory paths work correctly.
        ['data' => $this->formatDisplayName($file['name'])],
      ];

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
          '#url'   => $downloadUrl,
          '#attributes' => [
            'class' => ['button', 'button--small'],
          ],
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
      '#attached'   => [
        'library' => ['azure_storage_browser/styles'],
      ],
      '#cache'      => [
        // Do not cache the listing; files change frequently.
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Streams a file's content back to the client through the Drupal server.
   *
   * The download is proxied rather than redirecting the browser to a SAS
   * URL, so only the Drupal server needs network access to the storage
   * account — important when the account's firewall is restricted to
   * specific IP ranges that don't (and can't reliably) include end-user
   * browsers.
   *
   * The file path is passed as a base64-encoded route parameter to safely
   * handle paths containing slashes or special characters.
   */
  public function downloadFile(Request $request, string $file): Response {
    // Decode and validate the file path.
    $filePath = base64_decode($file, strict: true);
    if ($filePath === false || $filePath === '') {
      throw new NotFoundHttpException();
    }

    // Extension guard: re-check against allowed list on the server side.
    $config = $this->settings;
    $rawExtensions = (string) ($config->get('allowed_extensions') ?? '');
    $allowedExtensions = $this->parseExtensions($rawExtensions);

    if ($allowedExtensions !== [] &&
        !in_array($this->fileExtension($filePath), $allowedExtensions, true)) {
      throw new AccessDeniedHttpException('This file type is not permitted for download.');
    }

    // Fail fast (with a friendly redirect) on the common misconfiguration
    // case, before committing to a streamed response whose headers can't
    // be changed once sent.
    try {
      $this->azureService->assertConfigured();
    }
    catch (\RuntimeException $e) {
      $this->messenger()->addError($this->t(
        'Could not download file: @message',
        ['@message' => $e->getMessage()]
      ));
      return $this->redirect('azure_storage_browser.file_share.list');
    }

    $azureService = $this->azureService;
    $filename = basename($filePath);

    $response = new StreamedResponse(function () use ($azureService, $filePath): void {
      $destination = fopen('php://output', 'wb');
      try {
        $azureService->downloadFile($filePath, $destination);
      }
      catch (\RuntimeException $e) {
        // Headers are already committed by the time this callback runs, so
        // a clean error page/redirect isn't possible here. Log it for site
        // admins and leave a short message in the response body.
        \Drupal::logger('azure_storage_browser')->error(
          'File Share download failed for @file: @message',
          ['@file' => $filePath, '@message' => $e->getMessage()]
        );
        echo 'Download failed: ' . $e->getMessage();
      }
      finally {
        fclose($destination);
      }
    });

    $response->headers->set('Content-Type', 'application/octet-stream');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . addslashes($filename) . '"');
    $response->headers->set('Cache-Control', 'no-store, no-cache');

    return $response;
  }

}
