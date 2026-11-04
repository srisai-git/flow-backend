<?php
/**
 * Centralized HTTP Response Helper
 * Provides standard JSON response structures across all FocusFlow API endpoints.
 */

class Response {

    public static function json($data, int $statusCode = 200): void {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    public static function success($data = null, string $message = "Success", int $statusCode = 200): void {
        $payload = [
            'status' => true,
            'message' => $message
        ];
        if ($data !== null) {
            $payload['data'] = $data;
        }
        self::json($payload, $statusCode);
    }

    public static function error(string $message = "An error occurred", int $statusCode = 400, $errors = null): void {
        $payload = [
            'status' => false,
            'message' => $message
        ];
        if ($errors !== null) {
            $payload['errors'] = $errors;
        }
        self::json($payload, $statusCode);
    }

    public static function notFound(string $message = "Resource not found"): void {
        self::error($message, 404);
    }

    public static function unauthorized(string $message = "Unauthorized access"): void {
        self::error($message, 401);
    }

    public static function serverError(string $message = "Internal server error"): void {
        self::error($message, 500);
    }
}
