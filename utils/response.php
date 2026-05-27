<?php
declare(strict_types=1);

/**
 * JSON Response Builder
 * Standardized API response format for the entire platform
 */

class Response
{
    /**
     * Send a successful JSON response
     */
    public static function success(array $data = [], int $statusCode = 200): void
    {
        // Clean any buffered output (warnings, whitespace) before sending JSON
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'data'    => $data,
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    /**
     * Send an error JSON response
     * @param array|null $data Optional extra data (e.g. tenant_slug hint for redirects)
     */
    public static function error(string $message, string $code = 'ERROR', int $statusCode = 400, ?array $data = null): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        $response = [
            'success' => false,
            'error'   => $message,
            'code'    => $code,
        ];
        if ($data !== null) {
            $response['data'] = $data;
        }
        echo json_encode($response, JSON_THROW_ON_ERROR);
        exit;
    }

    /**
     * Send a 401 Unauthorized response
     */
    public static function unauthorized(string $message = 'Authentication required'): void
    {
        self::error($message, 'UNAUTHORIZED', 401);
    }

    /**
     * Send a 403 Forbidden response
     */
    public static function forbidden(string $message = 'Access denied'): void
    {
        self::error($message, 'FORBIDDEN', 403);
    }

    /**
     * Send a 404 Not Found response
     */
    public static function notFound(string $message = 'Resource not found'): void
    {
        self::error($message, 'NOT_FOUND', 404);
    }

    /**
     * Send a 422 Validation Error response
     */
    public static function validationError(array $errors, string $message = 'Validation failed'): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error'   => $message,
            'code'    => 'VALIDATION_ERROR',
            'errors'  => $errors,
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    /**
     * Send a 500 Internal Server Error response
     */
    public static function internalError(string $message = 'Internal server error'): void
    {
        self::error($message, 'INTERNAL_ERROR', 500);
    }
}
