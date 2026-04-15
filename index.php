<?php
/**
 * HelaPay Merchant QR - Secure REST API Entry Point
 * Routes incoming requests to the correct controller.
 *
 * Usage:
 *   POST /auth/token
 *   POST /auth/refresh
 *   POST /auth/logout
 *   POST /qr/generate
 *   POST /payment/status
 *   POST /payment/history
 *   POST /payment/callback   ← HelaPay webhook endpoint
 */

declare(strict_types=1);

require_once __DIR__ . '/config/Config.php';
require_once __DIR__ . '/helpers/Response.php';
require_once __DIR__ . '/helpers/Validator.php';
require_once __DIR__ . '/services/HelaPayService.php';
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/QrController.php';
require_once __DIR__ . '/controllers/PaymentController.php';

// ── Security headers ──────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header('Cache-Control: no-store, no-cache, must-revalidate');

// ── CORS (adjust origins for production) ─────────────────────────────────────
$allowedOrigins = Config::get('allowed_origins', ['*']);
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true) || $allowedOrigins === ['*']) {
    header('Access-Control-Allow-Origin: ' . ($allowedOrigins === ['*'] ? '*' : $origin));
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Rate limiting (simple file-based; swap for Redis in production) ───────────
RateLimiter::check();

// ── Route ─────────────────────────────────────────────────────────────────────
$path   = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$method = $_SERVER['REQUEST_METHOD'];

try {
    match (true) {
        $method === 'POST' && $path === 'auth/token'        => (new AuthController())->token(),
        $method === 'POST' && $path === 'auth/refresh'      => (new AuthController())->refresh(),
        $method === 'POST' && $path === 'auth/logout'       => (new AuthController())->logout(),
        $method === 'POST' && $path === 'qr/generate'       => (new QrController())->generate(),
        $method === 'POST' && $path === 'payment/status'    => (new PaymentController())->status(),
        $method === 'POST' && $path === 'payment/history'   => (new PaymentController())->history(),
        $method === 'POST' && $path === 'payment/callback'  => (new PaymentController())->callback(),
        default => Response::error('Route not found', 404),
    };
} catch (Throwable $e) {
    error_log('[HelaPay] Unhandled exception: ' . $e->getMessage());
    Response::error('Internal server error', 500);
}


// ── Simple rate limiter ───────────────────────────────────────────────────────
class RateLimiter
{
    private const MAX_REQUESTS = 60;   // per window
    private const WINDOW_SEC   = 60;

    public static function check(): void
    {
        $ip    = self::clientIp();
        $key   = sys_get_temp_dir() . '/ratelimit_' . md5($ip);
        $now   = time();
        $data  = file_exists($key) ? json_decode(file_get_contents($key), true) : ['count' => 0, 'start' => $now];

        if ($now - $data['start'] > self::WINDOW_SEC) {
            $data = ['count' => 0, 'start' => $now];
        }

        $data['count']++;
        file_put_contents($key, json_encode($data), LOCK_EX);

        if ($data['count'] > self::MAX_REQUESTS) {
            header('Retry-After: ' . (self::WINDOW_SEC - ($now - $data['start'])));
            Response::error('Too many requests', 429);
            exit;
        }
    }

    private static function clientIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $h) {
            if (!empty($_SERVER[$h])) {
                return explode(',', $_SERVER[$h])[0];
            }
        }
        return 'unknown';
    }
}
