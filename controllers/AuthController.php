<?php

declare(strict_types=1);

/**
 * AuthController
 * Handles /auth/token, /auth/refresh, /auth/logout
 */
class AuthController
{
    private HelaPayService $service;

    public function __construct()
    {
        $this->service = new HelaPayService();
        $this->requireApiKey();   // Protect all auth endpoints with your own API key
    }

    /** POST /auth/token — get a fresh access token from HelaPay */
    public function token(): void
    {
        try {
            $result = $this->service->generateToken();
            // Never expose refreshToken to the client — store it server-side
            Response::success([
                'accessToken' => $result['accessToken'],
                'expiresIn'   => $this->tokenTtl($result['accessToken']),
            ], 'Token generated');
        } catch (Throwable $e) {
            error_log('[Auth] token error: ' . $e->getMessage());
            Response::error('Failed to generate token: ' . $e->getMessage(), 502);
        }
    }

    /** POST /auth/refresh — refresh using a stored refresh token */
    public function refresh(): void
    {
        $body = Validator::jsonBody();

        $v = (new Validator())->required($body, ['refreshToken']);
        if (!$v->passes()) {
            Response::error('Validation failed', 422, $v->errors());
        }

        try {
            $result = $this->service->refreshToken($body['refreshToken']);
            $tokens = $result['data'][0] ?? $result;
            Response::success([
                'accessToken' => $tokens['accessToken'],
                'expiresIn'   => $this->tokenTtl($tokens['accessToken']),
            ], 'Token refreshed');
        } catch (Throwable $e) {
            error_log('[Auth] refresh error: ' . $e->getMessage());
            Response::error('Token refresh failed: ' . $e->getMessage(), 502);
        }
    }

    /** POST /auth/logout — revoke the current session */
    public function logout(): void
    {
        $body = Validator::jsonBody();

        $v = (new Validator())->required($body, ['refreshToken']);
        if (!$v->passes()) {
            Response::error('Validation failed', 422, $v->errors());
        }

        try {
            $this->service->revokeToken($body['refreshToken']);
            Response::success(null, 'Logged out successfully');
        } catch (Throwable $e) {
            error_log('[Auth] logout error: ' . $e->getMessage());
            Response::error('Logout failed: ' . $e->getMessage(), 502);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Verify a static API key sent via X-API-Key header. */
    private function requireApiKey(): void
    {
        $expectedKey = Config::get('api_key');
        if (!$expectedKey) {
            return;   // API key protection disabled (dev mode)
        }
        $providedKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
        if (!hash_equals($expectedKey, $providedKey)) {
            Response::error('Unauthorized', 401);
        }
    }

    /** Return seconds-until-expiry from a JWT. */
    private function tokenTtl(string $token): int
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return 0;
        }
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        return isset($payload['exp']) ? max(0, (int)$payload['exp'] - time()) : 0;
    }
}
