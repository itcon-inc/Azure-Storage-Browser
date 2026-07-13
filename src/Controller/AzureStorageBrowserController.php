<?php

declare(strict_types=1);

namespace Drupal\azure_storage_browser\Controller;

use Drupal\azure_storage_browser\AzureBlobStorageService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for the Azure Storage Browser pages.
 */
class AzureStorageBrowserController extends ControllerBase {

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
        ['data' => $this->formatBlobName($blob['name'])],
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
   * Redirects the user to a time-limited SAS download URL.
   *
   * The blob name is passed as a base64-encoded route parameter to safely
   * handle blobs whose names contain slashes or special characters.
   */
  public function downloadFile(Request $request, string $blob): RedirectResponse {
    // Decode and validate the blob name.
    $blobName = base64_decode($blob, strict: true);
    if ($blobName === false || $blobName === '') {
      throw new NotFoundHttpException();
    }

    // Extension guard: re-check against allowed list on the server side.
    $config = $this->configFactory->get('azure_storage_browser.settings');
    $rawExtensions = (string) ($config->get('allowed_extensions') ?? '');
    $allowedExtensions = $this->parseExtensions($rawExtensions);

    if ($allowedExtensions !== [] &&
        !in_array($this->fileExtension($blobName), $allowedExtensions, true)) {
      throw new AccessDeniedHttpException('This file type is not permitted for download.');
    }

    try {
      $sasUrl = $this->azureService->generateSasUrl($blobName);
    }
    catch (\RuntimeException $e) {
      $this->messenger()->addError($this->t(
        'Could not generate a download link: @message',
        ['@message' => $e->getMessage()]
      ));
      return $this->redirect('azure_storage_browser.list');
    }

    // 302 redirect to the SAS URL so the browser starts the download directly.
    return new RedirectResponse($sasUrl, 302, [
      'Cache-Control' => 'no-store, no-cache',
    ]);
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * Returns just the trailing filename from a (potentially path-like) blob name.
   */
  private function formatBlobName(string $name): string {
    return htmlspecialchars(basename($name), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  /**
   * Returns the lowercase file extension of a blob name, without the dot.
   */
  private function fileExtension(string $name): string {
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    return strtolower($ext);
  }

  /**
   * Parses a comma-separated extension string into a lowercase array.
   *
   * @return list<string>
   */
  private function parseExtensions(string $raw): array {
    if (trim($raw) === '') {
      return [];
    }
    return array_values(array_filter(
      array_map(
        static fn(string $e) => strtolower(trim($e, " \t.")),
        explode(',', $raw)
      )
    ));
  }

  /**
   * Formats a byte count into a human-readable string.
   */
  private function formatBytes(int $bytes): string {
    if ($bytes >= 1_073_741_824) {
      return number_format($bytes / 1_073_741_824, 2) . ' GB';
    }
    if ($bytes >= 1_048_576) {
      return number_format($bytes / 1_048_576, 2) . ' MB';
    }
    if ($bytes >= 1_024) {
      return number_format($bytes / 1_024, 2) . ' KB';
    }
    return $bytes . ' B';
  }

  /**
   * Formats an RFC 1123 date string into a site-locale-aware date/time string.
   */
  private function formatDate(string $rfc1123): string {
    try {
      $dt = new \DateTimeImmutable($rfc1123, new \DateTimeZone('UTC'));
      return $dt->format('Y-m-d H:i') . ' UTC';
    }
    catch (\Exception) {
      return $rfc1123;
    }
  }

}
