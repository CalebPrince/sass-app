<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\User;
use App\Models\Tenant;
use App\Models\Subscription;
use App\Models\UsageLog;

/**
 * Registration, login, logout, session identity, and password change.
 *
 * Registration is the front door of the SaaS: it atomically provisions a
 * tenant, an owner user, and a default (Starter) subscription, then logs the
 * user straight in so the frontend can redirect to the Client Control Center.
 */
final class AuthController extends Controller
{
    public function register(Request $request): void
    {
        $data = $this->require($request, ['company', 'name', 'email', 'password']);

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            Response::error('Please provide a valid email address', 422);
        }
        if (!$this->validPassword($data['password'])) {
            Response::error(
                'Password must be at least ' . $this->config['security']['password_min_len']
                . ' characters and include a letter and a number.',
                422
            );
        }
        if (User::findByEmail($data['email']) !== null) {
            Response::error('An account with that email already exists', 409);
        }

        $tier = 'starter';
        $tierConfig = $this->tier($tier);

        // One transaction: tenant + owner + subscription are all-or-nothing.
        $user = \App\Core\Database::instance()->transaction(function () use ($data, $tier, $tierConfig) {
            $tenant = Tenant::create($data['company']);
            $user = User::create((int) $tenant['id'], $data['email'], $data['password'], $data['name'], 'owner');
            Subscription::provision((int) $tenant['id'], $tier, $tierConfig);
            UsageLog::record((int) $tenant['id'], (int) $user['id'], 'account.created', 'tenant', 1, ['tier' => $tier]);
            return $user;
        });

        Session::login($user);
        Response::created([
            'user'     => User::publicView($user),
            'csrf'     => Session::csrfToken(),
            'redirect' => '/app',
        ], 'Welcome to Nimbus');
    }

    public function login(Request $request): void
    {
        $data = $this->require($request, ['email', 'password']);
        $user = User::findByEmail($data['email']);

        // Uniform failure message — do not reveal whether the email exists.
        if ($user === null || !User::verifyPassword($user, $data['password'])) {
            Response::error('Invalid email or password', 401);
        }
        if ($user['status'] === 'banned') {
            Response::forbidden('This account has been suspended.');
        }

        User::touchLogin((int) $user['id']);
        Session::login($user);
        UsageLog::record((int) $user['tenant_id'], (int) $user['id'], 'auth.login');

        // Operators land in the global console; everyone else in their app.
        $redirect = $user['role'] === 'super_admin' ? '/admin' : '/app';
        Response::ok([
            'user'     => User::publicView($user),
            'csrf'     => Session::csrfToken(),
            'redirect' => $redirect,
        ], 'Signed in');
    }

    public function logout(Request $request): void
    {
        Session::logout();
        Response::ok([], 'Signed out');
    }

    /**
     * Identity endpoint used by the frontend to hydrate + get a CSRF token.
     * Intentionally succeeds for anonymous sessions too: the register/login
     * forms need a CSRF token before there is any user. Returns user=null when
     * not signed in.
     */
    public function me(Request $request): void
    {
        $user = Session::check() ? User::find(Session::userId()) : null;
        Response::ok([
            'user' => $user ? User::publicView($user) : null,
            'csrf' => Session::csrfToken(),
        ]);
    }

    public function changePassword(Request $request): void
    {
        $data = $this->require($request, ['current_password', 'new_password']);
        $user = User::find(Session::userId());

        if ($user === null || !User::verifyPassword($user, $data['current_password'])) {
            Response::error('Current password is incorrect', 403);
        }
        if (!$this->validPassword($data['new_password'])) {
            Response::error('New password does not meet the strength requirements', 422);
        }

        User::updatePassword((int) $user['id'], $data['new_password']);
        UsageLog::record((int) $user['tenant_id'], (int) $user['id'], 'auth.password_changed');
        Response::ok([], 'Password updated');
    }
}
