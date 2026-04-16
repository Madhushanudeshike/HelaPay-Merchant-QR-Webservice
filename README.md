# HelaPay Merchant QR - PHP REST API

Secure PHP wrapper for the HelaPay (HelaPOS) Merchant QR API v1.2.0.

## File structure

```
helapay-api/
├── index.php                  ← Entry point / router
├── .htaccess                  ← Routing + security rules
├── .env.example               ← Environment variable template
├── config/
│   └── Config.php             ← Centralised configuration
├── services/
│   └── HelaPayService.php     ← All HelaPay HTTP calls + token management
├── controllers/
│   ├── AuthController.php     ← /auth/* endpoints
│   ├── QrController.php       ← /qr/generate
│   └── PaymentController.php  ← /payment/* + webhook
└── helpers/
    ├── Response.php           ← JSON response helper
    └── Validator.php          ← Input validation
```

## Quick start

1. Copy files to your web root (or a subdirectory).
2. Copy `.env.example` → `.env` and fill in your credentials.
3. Load `.env` values at bootstrap (e.g. via `vlucas/phpdotenv` or `putenv()`).
4. Point your web server to `index.php` (Apache: `.htaccess` already configured).

## Endpoints

| Method | Path               | Auth           | Description                        |
|--------|--------------------|----------------|------------------------------------|
| POST   | /auth/token        | X-API-Key      | Get fresh HelaPay access token     |
| POST   | /auth/refresh      | X-API-Key      | Refresh an existing session        |
| POST   | /auth/logout       | X-API-Key      | Revoke session                     |
| POST   | /qr/generate       | X-API-Key      | Generate a dynamic payment QR code |
| POST   | /payment/status    | X-API-Key      | Check payment status               |
| POST   | /payment/history   | X-API-Key      | Retrieve transaction history       |
| POST   | /payment/callback  | HMAC signature | Webhook from HelaPay               |

### Example: generate QR

```bash
curl -X POST https://yourdomain.com/qr/generate \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your_api_key" \
  -d '{"businessId":"26","reference":"order_001","amount":2500.00}'
```

Response:
```json
{
  "success": true,
  "message": "QR code generated",
  "data": {
    "qrData": "000201...",
    "qrReference": "0002300000509...",
    "reference": "order_001"
  }
}
```

### Example: check payment status

```bash
curl -X POST https://yourdomain.com/payment/status \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your_api_key" \
  -d '{"reference":"order_001"}'
```

## Security features

- **API key** (`X-API-Key` header) on every endpoint except the webhook.
- **HMAC-SHA256 signature verification** on the webhook callback.
- **Rate limiting** — 60 requests / minute per IP (swap for Redis in production).
- **Token caching** — access tokens are stored server-side; refresh is automatic.
- **Constant-time comparisons** (`hash_equals`) to prevent timing attacks.
- **SSL verification** enforced on all outbound cURL calls.
- **No sensitive data** (refresh tokens, secrets) returned to clients.
- **Security headers** — HSTS, X-Frame-Options, X-Content-Type-Options, etc.

## Production checklist

- [ ] Set real values in `.env` / server environment — never hard-code secrets.
- [ ] Move `TOKEN_CACHE_DIR` outside the web root.
- [ ] Replace file-based rate limiting with Redis (e.g. `predis/predis`).
- [ ] Add a database layer in `handleSuccess()` / `handleFailure()`.
- [ ] Enable PHP error logging to a file, not `display_errors`.
- [ ] Run behind HTTPS only.
- [ ] Restrict `ALLOWED_ORIGINS` to your own domain(s).
