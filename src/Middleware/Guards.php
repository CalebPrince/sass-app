<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Database;

/**
 * Request guards. Each returns TRUE to let the request proceed, or emits a
 * terminal JSON response and returns FALSE to stop it at the router.
 *
 * These are the "security guards against unauthorized state bypasses":
 *   - AuthGuard   : you must be logged in.
 *   - CsrfGuard   : mutating requests must carry a matching CSRF token.
 *   - AdminGuard  : you must be a platform super_admin (global console).
 *   - ActiveGuard : your account and tenant must not be banned/suspended.
 */
final class Guards
{
    /** Require an authenticated session. */
    public static function auth(Request $request): bool
    {
        if (!Session::check()) {
            Response::unauthorized();
            return false;
        }
        return true;
    }

    /**
     * Require the caller to still be in good standing. A banned user or a
     * suspended tenant is force-logged-out — no lingering valid session.
     */
    public static function active(Request $request): bool
    {
        $db = Database::instance();
        $row = $db->first(
            'SELECT u.status AS user_status, t.status AS tenant_status
               FROM users u JOIN tenants t ON t.id = u.tenant_id
              WHERE u.id = ?',
            [Session::userId()]
        );

        if ($row === null || $row['user_status'] === 'banned' || $row['tenant_status'] === 'suspended') {
            Session::logout();
            Response::forbidden('Your account has been suspended.');
            return false;
        }
        return true;
    }

    /** CSRF double-submit check for POST/PATCH/DELETE. */
    public static function csrf(Request $request): bool
    {
        if (in_array($request->method, ['POST', 'PATCH', 'PUT', 'DELETE'], true)) {
            $token = $request->header('x-csrf-token') ?? (string) $request->input('_csrf', '');
            if (!Session::verifyCsrf($token)) {
                Response::forbidden('Invalid or missing CSRF token.');
                return false;
            }
        }
        return true;
    }

    /** Restrict to platform operators. */
    public static function admin(Request $request): bool
    {
        if (!Session::check()) {
            Response::unauthorized();
            return false;
        }
        if (Session::role() !== 'super_admin') {
            Response::forbidden('Operator access only.');
            return false;
        }
        return true;
    }
}
