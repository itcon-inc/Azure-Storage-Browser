<?php

declare(strict_types=1);

namespace Drupal\azure_storage_browser;

/**
 * Shared Shared-Key REST helpers for the Blob Storage and File Share
 * services: request signing, canonicalisation, and a streaming HTTP GET
 * used to proxy downloads through the Drupal server.
 *
 * Proxying (rather than redirecting the browser to a SAS URL) means the
 * end user's client never needs direct network access to the storage
 * account — only the Drupal server does. This matters for storage accounts
 * locked down with IP/VNet firewall rules where end-user IPs are unknown
 * or can't be allow-listed.
 */
trait AzureRestClientTrait {

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
  private function canonicaliseResource(string $account, string $path, array $queryParams = []): string {
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
      $message = $this->extractAzureError((string) $body) ?? "HTTP {$status}";
      throw new \RuntimeException("Azure returned an error: {$message}");
    }

    return (string) $body;
  }

  /**
   * Performs an HTTP GET request and streams the response body directly to
   * $destination as it arrives, rather than buffering the whole payload in
   * PHP memory. Used to proxy file/blob downloads through the Drupal server.
   *
   * If Azure responds with a non-2xx status, the (typically small) error
   * body is buffered in memory instead of being written to $destination, so
   * a clean exception can be thrown without corrupting the output stream.
   *
   * @param resource $destination
   *   A writable stream, e.g. fopen('php://output', 'wb').
   *
   * @return array{content_type: string, content_length: ?int}
   *
   * @throws \RuntimeException on cURL or HTTP errors.
   */
  private function httpGetStream(string $url, array $headers, $destination): array {
    $curlHeaders = [];
    foreach ($headers as $name => $value) {
      $curlHeaders[] = "{$name}: {$value}";
    }

    $status = 0;
    $responseHeaders = [];
    $errorBuffer = '';

    $ch = curl_init($url);
    if ($ch === false) {
      throw new \RuntimeException('Failed to initialise cURL.');
    }

    curl_setopt_array($ch, [
      CURLOPT_HTTPHEADER => $curlHeaders,
      CURLOPT_TIMEOUT => 300,
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_HEADERFUNCTION => function ($handle, string $headerLine) use (&$responseHeaders): int {
        $len = strlen($headerLine);
        $parts = explode(':', $headerLine, 2);
        if (count($parts) === 2) {
          $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
        return $len;
      },
      CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$status, $destination, &$errorBuffer): int {
        if ($status === 0) {
          $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        }
        if ($status >= 200 && $status < 300) {
          fwrite($destination, $chunk);
        }
        else {
          // Buffer rather than write: non-2xx bodies are small Azure error
          // XML, and we don't want to corrupt the download with them.
          $errorBuffer .= $chunk;
        }
        return strlen($chunk);
      },
    ]);

    $ok = curl_exec($ch);
    $curlError = curl_error($ch);
    $finalStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($ok === false || $curlError !== '') {
      throw new \RuntimeException("cURL error contacting Azure: {$curlError}");
    }

    if ($finalStatus < 200 || $finalStatus >= 300) {
      $message = $this->extractAzureError($errorBuffer) ?? "HTTP {$finalStatus}";
      throw new \RuntimeException("Azure returned an error: {$message}");
    }

    return [
      'content_type'   => $responseHeaders['content-type'] ?? 'application/octet-stream',
      'content_length' => isset($responseHeaders['content-length']) ? (int) $responseHeaders['content-length'] : null,
    ];
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
