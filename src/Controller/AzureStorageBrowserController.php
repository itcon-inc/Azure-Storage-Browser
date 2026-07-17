<?php

declare(strict_types=1);

namespace Drupal\azure_storage_browser\Controller;

use Drupal\azure_storage_browser\AzureBlobStorageService;
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
 * Controller for the Azure Blob Storage browser pages.
 */
class AzureStorageBrowserController extends ControllerBase {

  use AzureStorageDisplayHelpersTrait;

  public $azureService;
  public $settings;

  /**
   * Constructs an AzureStorageBrowserController object.
   *
   * @param \Drupal\storage_brwoser\AzureBlobStorageService $azureService
   *   The module handler.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    AzureBlobStorageService $azureService,
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
      $container->get('azure_storage_browser.blob_storage'),
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
    $config         = $this->settings;
    $showSize       = (bool) $config->get('show_file_size');
    $showModified   = (bool) $config->get('show_last_modified');
    $rawExtensions  = (string) ($config->get('allowed_extensions') ?? '');
    $allowedExtensions = $this->parseExtensions($rawExtensions);

    // Attempt to list blobs; surface configuration errors gracefully.
    try {
      $blobs = $this->azureService->listBlobs();
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
      $blobs = array_values(array_filter(
        $blobs,
        fn(array $b) => in_array($this->fileExtension($b['name']), $allowedExtensions, true)
      ));
    }

    if ($blobs === []) {
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
    foreach ($blobs as $blob) {
      $downloadUrl = Url::fromRoute(
        'azure_storage_browser.download',
        ['blob' => base64_encode($blob['name'])],
        ['absolute' => FALSE]
      );

      $row = [
        // Display just the filename portion, but use the full blob name for
        // the download route so virtual-directory paths work correctly.
        ['data' => $this->formatDisplayName($blob['name'])],
      ];

      if ($showSize) {
        $row[] = ['data' => $this->formatBytes($blob['size'])];
      }

      if ($showModified) {
        $row[] = ['data' => $this->formatDate($blob['last_modified'])];
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
        // Do not cache the listing; blobs change frequently.
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Streams a blob's content back to the client through the Drupal server.
   *
   * The download is proxied rather than redirecting the browser to a SAS
   * URL, so only the Drupal server needs network access to the storage
   * account — important when the account's firewall is restricted to
   * specific IP ranges that don't (and can't reliably) include end-user
   * browsers.
   *
   * The blob name is passed as a base64-encoded route parameter to safely
   * handle blobs whose names contain slashes or special characters.
   */
  public function downloadFile(Request $request, string $blob): Response {
    // Decode and validate the blob name.
    $blobName = base64_decode($blob, strict: true);
    if ($blobName === false || $blobName === '') {
      throw new NotFoundHttpException();
    }

    // Extension guard: re-check against allowed list on the server side.
    $config = $this->settings;
    $rawExtensions = (string) ($config->get('allowed_extensions') ?? '');
    $allowedExtensions = $this->parseExtensions($rawExtensions);

    if ($allowedExtensions !== [] &&
        !in_array($this->fileExtension($blobName), $allowedExtensions, true)) {
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
      return $this->redirect('azure_storage_browser.list');
    }

    $azureService = $this->azureService;
    $filename = basename($blobName);

    $response = new StreamedResponse(function () use ($azureService, $blobName): void {
      $destination = fopen('php://output', 'wb');
      try {
        $azureService->downloadBlob($blobName, $destination);
      }
      catch (\RuntimeException $e) {
        // Headers are already committed by the time this callback runs, so
        // a clean error page/redirect isn't possible here. Log it for site
        // admins and leave a short message in the response body.
        \Drupal::logger('azure_storage_browser')->error(
          'Blob download failed for @blob: @message',
          ['@blob' => $blobName, '@message' => $e->getMessage()]
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
