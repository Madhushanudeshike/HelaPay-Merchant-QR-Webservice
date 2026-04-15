<?php

declare(strict_types=1);

/**
 * HelaPayService
 *
 * All HTTP communication with the HelaPay gateway lives here.
 * Controllers call this service — they never call cURL directly.
 *
 * Token management
 * ─────────────────
 * Access tokens are cached on disk (Config::token_cache_dir).
 * getAccessToken() returns a valid token, refreshing automatically
 * when the cached token is within 60 seconds of expiry.
 */
class HelaPayService
{
    private string $baseUrl;
    private int    $timeout;
    private string $cacheDir;

    public function __construct()
    {
        $this->baseUrl  = rtrim(Config::get('hela_base_url'), '/');
        $this->timeout  = (int) Config::get('http_timeout_sec', 15);
        $this->cacheDir = Config::get('token_cache_dir', '/tmp/helapay_tokens');

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0700, true);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Public API methods
    // ──────────────────────────────────────────────────────────────────────────

    /** Fetch a new access + refresh token pair using Basic auth. */
    public function generateToken(): array
    {
        $response = $this->post(
            '/merchant/api/v1/getToken',
            ['grant_type' => 'client_credentials'],
            ['Authorization: Basic ' . Config::basicAuth()]
        );

        // Cache the new tokens
        $this->saveTokens($response);
        return $response;
    }

    /** Refresh an existing session. */
    public function refreshToken(string $refreshToken): array
    {
        $response = $this->post(
            '/merchant/api/v1/merchant/auth/refresh',
            ['refreshToken' => $refreshToken]
        );
        $this->saveTokens($response['data'][0] ?? $response);
        return $response;
    }

    /** Revoke session (logout). */
    public function revokeToken(string $refreshToken): array
    {
        $response = $this->post(
            '/merchant/api/v1/merchant/auth/logout',
            ['refreshToken' => $refreshToken],
            ['Authorization: Bearer ' . $this->getAccessToken()]
        );
        $this->clearTokens();
        return $response;
    }

    /** Generate a dynamic QR code. */
    public function generateQr(string $businessId, string $reference, float $amount): array
    {
        return $this->post(
            '/merchant/api/helapos/qr/generate',
            ['b' => $businessId, 'r' => $reference, 'am' => $amount],
            ['Authorization: Bearer ' . $this->getAccessToken()]
        );
    }

    /** Check the payment status of a transaction. */
    public function getSaleStatus(string $reference = '', string $qrReference = ''): array
    {
        if (!$reference && !$qrReference) {
            throw new InvalidArgumentException('Provide at least reference or qr_reference.');
        }
        $payload = array_filter([
            'reference'    => $reference,
            'qr_reference' => $qrReference,
        ]);
        return $this->post(
            '/merchant/api/helapos/sales/getSaleStatus',
            $payload,
            ['Authorization: Bearer ' . $this->getAccessToken()]
        );
    }

    /** Retrieve transaction history for a date range. */
    public function getTransactionHistory(string $businessId, string $start, string $end): array
    {
        return $this->post(
            '/merchant/api/helapos/sales',
            ['businessId' => $businessId, 'start' => $start, 'end' => $end],
            ['Authorization: Bearer ' . $this->getAccessToken()]
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Token cache helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Return a valid access token, auto-refreshing if close to expiry.
     */
    public function getAccessToken(): string
    {
        $cache = $this->loadTokens();

        if (!$cache) {
            // No token cached — fetch a fresh one
            $response = $this->generateToken();
            return $response['accessToken'] ?? throw new RuntimeException('Failed to obtain access token.');
        }

        // If within 60 s of expiry, refresh
        $expiresAt = $cache['expires_at'] ?? 0;
        if (time() >= $expiresAt - 60) {
            $refreshToken = $cache['refreshToken'] ?? '';
            if (!$refreshToken) {
                $response = $this->generateToken();
                return $response['accessToken'];
            }
            $response = $this->refreshToken($refreshToken);
            $tokens   = $response['data'][0] ?? $response;
            return $tokens['accessToken'] ?? throw new RuntimeException('Failed to refresh access token.');
        }

        return $cache['accessToken'];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // HTTP client
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * POST JSON to the HelaPay API.
     *
     * @param  array<string> $extraHeaders Additional headers (e.g. Authorization)
     * @return array<mixed>  Decoded JSON response
     */
    private function post(string $endpoint, array $payload, array $extraHeaders = []): array
    {
        $url  = $this->baseUrl . $endpoint;
        $json = json_encode($payload);

        $headers = array_merge([
            'Content-Type: application/json',
            'Accept: application/json',
            'Content-Length: ' . strlen($json),
        ], $extraHeaders);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,          // Always verify SSL in production
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,          // Do not follow redirects for payment APIs
            CURLOPT_USERAGENT      => 'HelaPHP-Client/1.0',
        ]);

        $raw    = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log('[HelaPay] cURL error for ' . $endpoint . ': ' . $error);
            throw new RuntimeException('Gateway connection failed: ' . $error);
        }

        $data = json_decode($raw ?: '', true);
        if (!is_array($data)) {
            error_log('[HelaPay] Non-JSON response from ' . $endpoint . ' (HTTP ' . $status . '): ' . $raw);
            throw new RuntimeException('Invalid response from payment gateway.');
        }

        if ($status >= 400) {
            $msg = $data['message'] ?? $data['statusMessage'] ?? 'Gateway error';
            throw new RuntimeException($msg, $status);
        }

        return $data;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Token persistence (file-based cache)
    // ──────────────────────────────────────────────────────────────────────────

    private function cacheFile(): string
    {
        return $this->cacheDir . '/tokens.json';
    }

    private function saveTokens(array $tokens): void
    {
        // Decode expiry from JWT payload (claim "exp") if available
        $exp = $this->jwtExp($tokens['accessToken'] ?? '') ?? (time() + 600);
        $tokens['expires_at'] = $exp;
        file_put_contents($this->cacheFile(), json_encode($tokens), LOCK_EX);
        chmod($this->cacheFile(), 0600);   // owner-readable only
    }

    private function loadTokens(): ?array
    {
        $file = $this->cacheFile();
        if (!file_exists($file)) {
            return null;
        }
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    private function clearTokens(): void
    {
        $file = $this->cacheFile();
        if (file_exists($file)) {
            unlink($file);
        }
    }

    /** Extract the "exp" claim from a JWT without verifying the signature. */
    private function jwtExp(string $token): ?int
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        return isset($payload['exp']) ? (int)$payload['exp'] : null;
    }
}
