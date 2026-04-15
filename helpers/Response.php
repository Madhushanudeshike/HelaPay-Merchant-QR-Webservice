<?php

declare(strict_types=1);

/**
 * Standardised JSON response helper.
 */
class Response
{
    public static function success(mixed $data = null, string $message = 'Success', int $status = 200): never
    {
        http_response_code($status);
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function error(string $message, int $status = 400, array $errors = []): never
    {
        http_response_code($status);
        $body = [
            'success' => false,
            'message' => $message,
        ];
        if ($errors) {
            $body['errors'] = $errors;
        }
        echo json_encode($body, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
