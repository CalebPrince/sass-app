<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Hardened session facade over PHP's native session store.
 *
 * Security posture:
 *   * HttpOnly + SameSite cookies (config-driven), Secure in production.
 *   * ID regenerated on privilege change (login) to defeat session fixation.
 *   * Periodic ID rotation to shrink the window of a stolen session id.
 *   * A per-session CSRF token guards every state-changing request.
 */
final class Session
{
    private static array $config = [];

    public static function start(array $securityConfig): void
    {
        self::$config = $securityConfig;

        session_name($securityConfig['session_name']);
        session_set_cookie_params([
            'lifetime' => $securityConfig['session_lifetime'],
            'path'     => '/',
            'httponly' => $securityConfig['cookie_httponly'],
            'secure'   => $securityConfig['cookie_secure'],
            'samesite' => $securityConfig['cookie_samesite'],
        ]);

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        self::rotateIfStale();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    /** Rotate the session id on a timer without dropping session data. */
    private static function rotateIfStale(): void
    {
        $now = time();
        $rotate = self::$config['session_rotate'] ?? 1800;
        if (!isset($_SESSION['_rotated_at'])) {
            $_SESSION['_rotated_at'] = $now;
            return;
        }
        if ($now - (int) $_SESSION['_rotated_at'] > $rotate) {
            session_regenerate_id(true);
            $_SESSION['_rotated_at'] = $now;
        }
    }

    /** Promote an anonymous session to an authenticated one. */
    public static function login(array $user): void
    {
        session_regenerate_id(true);          // fresh id => no fixation
        $_SESSION['user_id']    = (int) $user['id'];
        $_SESSION['tenant_id']  = (int) $user['tenant_id'];
        $_SESSION['role']       = $user['role'];
        $_SESSION['_rotated_at'] = time();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function check(): bool
    {
        return !empty($_SESSION['user_id']);
    }

    public static function userId(): ?int
    {
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    public static function tenantId(): ?int
    {
        return isset($_SESSION['tenant_id']) ? (int) $_SESSION['tenant_id'] : null;
    }

    public static function role(): ?string
    {
        return $_SESSION['role'] ?? null;
    }

    public static function csrfToken(): string
    {
        return $_SESSION['csrf_token'] ?? '';
    }

    public static function verifyCsrf(?string $token): bool
    {
        return is_string($token)
            && !empty($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }
}
