<?php
declare(strict_types=1);

namespace App\Core;

/**
 * JSON-first response helper. Centralizes status codes and the envelope shape
 * so the vanilla-JS frontend can rely on a single, predictable contract.
 */
final class Response
{
    public static function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function ok(mixed $data = [], string $message = 'ok'): void
    {
        self::json(['success' => true, 'message' => $message, 'data' => $data], 200);
    }

    public static function created(mixed $data = [], string $message = 'created'): void
    {
        self::json(['success' => true, 'message' => $message, 'data' => $data], 201);
    }

    public static function error(string $message, int $status = 400, array $errors = []): void
    {
        self::json(['success' => false, 'message' => $message, 'errors' => $errors], $status);
    }

    public static function unauthorized(string $message = 'Authentication required'): void
    {
        self::error($message, 401);
    }

    public static function forbidden(string $message = 'You do not have access to this resource'): void
    {
        self::error($message, 403);
    }

    public static function notFound(string $message = 'Not found'): void
    {
        self::error($message, 404);
    }
}
