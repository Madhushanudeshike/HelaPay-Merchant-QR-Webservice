<?php

declare(strict_types=1);

/**
 * QrController
 * POST /qr/generate
 */
class QrController
{
    private HelaPayService $service;

    public function __construct()
    {
        $this->service = new HelaPayService();
        $this->requireApiKey();
    }

    /** POST /qr/generate */
    public function generate(): void
    {
        $body = Validator::jsonBody();

        $v = (new Validator())
            ->required($body, ['businessId', 'reference', 'amount'])
            ->numeric($body, 'amount', 0.01)
            ->maxLength($body, 'businessId', 64)
            ->maxLength($body, 'reference', 64);

        if (!$v->passes()) {
            Response::error('Validation failed', 422, $v->errors());
        }

        try {
            $result = $this->service->generateQr(
                (string) $body['businessId'],
                (string) $body['reference'],
                (float)  $body['amount']
            );

            if (($result['statusCode'] ?? '') !== '200') {
                Response::error($result['statusMessage'] ?? 'QR generation failed', 502);
            }

            Response::success([
                'qrData'      => $result['qr_data'],
                'qrReference' => $result['qr_reference'],
                'reference'   => $result['reference'],
            ], 'QR code generated');
        } catch (Throwable $e) {
            error_log('[QR] generate error: ' . $e->getMessage());
            Response::error('QR generation failed: ' . $e->getMessage(), 502);
        }
    }

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
}
