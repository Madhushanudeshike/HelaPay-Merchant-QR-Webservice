<?php

declare(strict_types=1);

/**
 * PaymentController
 *
 * POST /payment/status   — check payment status
 * POST /payment/history  — retrieve transaction history
 * POST /payment/callback — receive HelaPay webhook notifications
 */
class PaymentController
{
    private HelaPayService $service;

    public function __construct()
    {
        $this->service = new HelaPayService();
    }

    // ── Status ────────────────────────────────────────────────────────────────

    /** POST /payment/status */
    public function status(): void
    {
        $this->requireApiKey();
        $body = Validator::jsonBody();

        if (empty($body['reference']) && empty($body['qrReference'])) {
            Response::error('Provide at least "reference" or "qrReference"', 422);
        }

        try {
            $result = $this->service->getSaleStatus(
                $body['reference']   ?? '',
                $body['qrReference'] ?? ''
            );

            if (($result['statusCode'] ?? '') !== '200') {
                Response::error($result['statusMessage'] ?? 'Status check failed', 502);
            }

            $sale = $result['sale'] ?? [];
            Response::success([
                'reference'     => $result['reference'],
                'saleId'        => $sale['sale_id']       ?? null,
                'referenceId'   => $sale['reference_id']  ?? null,
                'amount'        => $sale['amount']         ?? null,
                'timestamp'     => $sale['timestamp']      ?? null,
                'paymentStatus' => $this->statusLabel((int)($sale['payment_status'] ?? -99)),
                'statusCode'    => (int)($sale['payment_status'] ?? -99),
            ]);
        } catch (Throwable $e) {
            error_log('[Payment] status error: ' . $e->getMessage());
            Response::error('Status check failed: ' . $e->getMessage(), 502);
        }
    }

    // ── History ───────────────────────────────────────────────────────────────

    /** POST /payment/history */
    public function history(): void
    {
        $this->requireApiKey();
        $body = Validator::jsonBody();

        $v = (new Validator())
            ->required($body, ['businessId', 'start', 'end'])
            ->date($body, 'start')
            ->date($body, 'end');

        if (!$v->passes()) {
            Response::error('Validation failed', 422, $v->errors());
        }

        // Ensure start <= end
        if ($body['start'] > $body['end']) {
            Response::error('"start" must be on or before "end"', 422);
        }

        // Limit date range to 31 days to prevent overly large queries
        $diff = (new DateTime($body['start']))->diff(new DateTime($body['end']))->days;
        if ($diff > 31) {
            Response::error('Date range cannot exceed 31 days', 422);
        }

        try {
            $result = $this->service->getTransactionHistory(
                (string) $body['businessId'],
                $body['start'],
                $body['end']
            );

            if (($result['statusCode'] ?? '') !== '200') {
                Response::error($result['statusMessage'] ?? 'History fetch failed', 502);
            }

            // Normalise each sale record
            $sales = array_map(fn($s) => [
                'saleId'        => $s['sale_id']      ?? null,
                'referenceId'   => $s['reference_id'] ?? null,
                'amount'        => $s['amount']        ?? null,
                'timestamp'     => $s['timestamp']     ?? null,
                'paymentStatus' => $this->statusLabel((int)($s['payment_status'] ?? -99)),
                'statusCode'    => (int)($s['payment_status'] ?? -99),
            ], $result['sales'] ?? []);

            Response::success([
                'businessId' => $result['business_id'] ?? $body['businessId'],
                'sales'      => $sales,
                'total'      => count($sales),
                'lastSync'   => $result['last_sync'] ?? null,
            ]);
        } catch (Throwable $e) {
            error_log('[Payment] history error: ' . $e->getMessage());
            Response::error('History fetch failed: ' . $e->getMessage(), 502);
        }
    }

    // ── Webhook callback ──────────────────────────────────────────────────────

    /**
     * POST /payment/callback
     *
     * Endpoint for HelaPay to notify your system about payment outcomes.
     * Verifies an HMAC-SHA256 signature sent in the X-HelaPay-Signature header
     * to ensure the request genuinely comes from HelaPay.
     *
     * Register this URL with HelaPay as your Notify URL.
     */
    public function callback(): void
    {
        $raw = file_get_contents('php://input');

        // ── Verify webhook signature ──────────────────────────────────────────
        $this->verifyWebhookSignature($raw);

        $data = json_decode($raw ?: '', true);
        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid payload']);
            exit;
        }

        // ── Process the notification ──────────────────────────────────────────
        $statusCode    = $data['statusCode']            ?? null;
        $reference     = $data['reference']             ?? '';
        $sale          = $data['sale']                  ?? [];
        $paymentStatus = (int)($sale['payment_status']  ?? -99);
        $amount        = $sale['amount']                ?? 0;
        $saleId        = $sale['sale_id']               ?? '';
        $timestamp     = $sale['timestamp']             ?? '';

        // Log every callback for auditing
        error_log(sprintf(
            '[Webhook] ref=%s saleId=%s status=%d amount=%s ts=%s',
            $reference, $saleId, $paymentStatus, $amount, $timestamp
        ));

        // ── Business logic based on payment status ────────────────────────────
        try {
            match ($paymentStatus) {
                2  => $this->handleSuccess($reference, $sale),
                -1 => $this->handleFailure($reference, $sale),
                0  => $this->handlePending($reference, $sale),
                default => error_log('[Webhook] Unknown status: ' . $paymentStatus),
            };
        } catch (Throwable $e) {
            // Log but still return 200 — do NOT let processing errors block the ACK
            error_log('[Webhook] Processing error: ' . $e->getMessage());
        }

        // HelaPay requires a 200 OK to acknowledge receipt
        http_response_code(200);
        echo json_encode(['received' => true]);
        exit;
    }

    // ── Webhook business logic callbacks ─────────────────────────────────────

    private function handleSuccess(string $reference, array $sale): void
    {
        // TODO: update order status in your database, send confirmation email, etc.
        error_log('[Webhook] Payment SUCCESS for reference: ' . $reference);
    }

    private function handleFailure(string $reference, array $sale): void
    {
        // TODO: mark order as failed, notify customer, etc.
        error_log('[Webhook] Payment FAILED for reference: ' . $reference);
    }

    private function handlePending(string $reference, array $sale): void
    {
        // TODO: schedule a status check, mark order as pending
        error_log('[Webhook] Payment PENDING for reference: ' . $reference);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function requireApiKey(): void
    {
        $expected = Config::get('api_key');
        if (!$expected) {
            return;
        }
        $provided = $_SERVER['HTTP_X_API_KEY'] ?? '';
        if (!hash_equals($expected, $provided)) {
            Response::error('Unauthorized', 401);
        }
    }

    /**
     * Verify that the incoming webhook was signed by HelaPay.
     * HelaPay sends: X-HelaPay-Signature: sha256=<hmac>
     *
     * Configure the shared secret in Config::webhook_secret.
     */
    private function verifyWebhookSignature(string $rawBody): void
    {
        $secret = Config::get('webhook_secret');
        if (!$secret) {
            // Signature verification disabled — not recommended for production
            return;
        }

        $header    = $_SERVER['HTTP_X_HELAPAY_SIGNATURE'] ?? '';
        $expected  = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);

        // Use hash_equals to prevent timing attacks
        if (!hash_equals($expected, $header)) {
            error_log('[Webhook] Signature mismatch. Got: ' . $header);
            http_response_code(401);
            echo json_encode(['error' => 'Invalid signature']);
            exit;
        }
    }

    private function statusLabel(int $code): string
    {
        return match ($code) {
            2  => 'success',
            -1 => 'failed',
            0  => 'pending',
            default => 'unknown',
        };
    }
}
