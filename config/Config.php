<?php

declare(strict_types=1);

/**
 * Central configuration.
 * Values are read from environment variables first; the array below
 * provides safe defaults for development.
 *
 * Set real values in your .env file or server environment — never
 * hard-code secrets in source code.
 */
class Config
{
    private static array $defaults = [
        // HelaPay gateway
        'hela_base_url'    => 'https://api.helapay.lk',       // Replace with actual base URL
        'hela_app_id'      => '',                              // Set via HELA_APP_ID env var
        'hela_app_secret'  => '',                              // Set via HELA_APP_SECRET env var

        // Your merchant details
        'business_id'      => '',                              // Set via HELA_BUSINESS_ID env var

        // Webhook security
        'webhook_secret'   => '',                              // Shared secret with HelaPay

        // API security
        'api_key'          => '',                              // Your own API key to protect these endpoints
        'allowed_origins'  => ['*'],                           // Replace with your domain(s)

        // Timeouts
        'http_timeout_sec' => 15,

        // Token cache directory (writable by web server)
        'token_cache_dir'  => '/tmp/helapay_tokens',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        // Environment variable takes precedence (e.g. HELA_APP_ID)
        $envKey = strtoupper($key);
        $envVal = getenv($envKey);
        if ($envVal !== false) {
            return $envVal;
        }
        return self::$defaults[$key] ?? $default;
    }

    /** Build the Basic Authorization header value from App ID + App Secret */
    public static function basicAuth(): string
    {
        $id     = self::get('hela_app_id');
        $secret = self::get('hela_app_secret');
        if (!$id || !$secret) {
            throw new RuntimeException('HelaPay App ID or Secret not configured.');
        }
        return base64_encode($id . ':' . $secret);
    }
}
