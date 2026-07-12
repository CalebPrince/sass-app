<?php
declare(strict_types=1);

/**
 * Front controller / application bootstrap.
 *
 * Responsibilities, in order:
 *   1. Autoload the App\ namespace from /src (no Composer required).
 *   2. Load config, boot the database, run migrations + seed on first run.
 *   3. Start the hardened session.
 *   4. Register page routes (serve HTML shells) and API routes (JSON).
 *   5. Dispatch.
 */

use App\Core\Database;
use App\Core\Migration;
use App\Core\Session;
use App\Core\Router;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\Guards;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\SubscriptionController;
use App\Controllers\AdminController;

// ---- 1. Autoloader --------------------------------------------------------
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// ---- 2. Config + database -------------------------------------------------
$config = require dirname(__DIR__) . '/config/config.php';
date_default_timezone_set($config['app']['timezone']);

error_reporting($config['app']['debug'] ? E_ALL : 0);
ini_set('display_errors', $config['app']['debug'] ? '1' : '0');

Database::boot($config['database']['path']);
$db = Database::instance();

if (Migration::isFresh($db)) {
    Migration::run($db, dirname(__DIR__) . '/database/schema.sql');
    require dirname(__DIR__) . '/database/seed.php';
    seed_database($db, $config);
} else {
    // Ensure any newly added tables exist (idempotent).
    Migration::run($db, dirname(__DIR__) . '/database/schema.sql');
}

// ---- 3. Session -----------------------------------------------------------
Session::start($config['security']);

// ---- 4. Routes ------------------------------------------------------------
$router = new Router();
$views  = dirname(__DIR__) . '/public/views';

$page = function (string $file) use ($views): callable {
    return function () use ($views, $file): void {
        header('Content-Type: text/html; charset=utf-8');
        readfile($views . '/' . $file);
        exit;
    };
};

// Public marketing + auth pages (server just ships the HTML shell; JS hydrates).
$router->get('/',          $page('landing.html'));
$router->get('/login',     $page('login.html'));
$router->get('/register',  $page('register.html'));
// Authenticated shells — the API behind them enforces real access control.
$router->get('/app',       $page('app.html'));
$router->get('/admin',     $page('admin.html'));

// --- API: authentication ---
$auth = new AuthController($config);
$router->post('/api/auth/register', [$auth, 'register'], [[Guards::class, 'csrf']]);
$router->post('/api/auth/login',    [$auth, 'login'],    [[Guards::class, 'csrf']]);
$router->post('/api/auth/logout',   [$auth, 'logout'],   [[Guards::class, 'auth'], [Guards::class, 'csrf']]);
$router->get('/api/auth/me',        [$auth, 'me']);
$router->post('/api/auth/password', [$auth, 'changePassword'], [[Guards::class, 'auth'], [Guards::class, 'active'], [Guards::class, 'csrf']]);

// --- API: client control center (tenant-scoped) ---
$clientGuards = [[Guards::class, 'auth'], [Guards::class, 'active']];
$clientWrite  = [[Guards::class, 'auth'], [Guards::class, 'active'], [Guards::class, 'csrf']];

$dash = new DashboardController($config);
$router->get('/api/dashboard/overview', [$dash, 'overview'], $clientGuards);
$router->post('/api/dashboard/track',   [$dash, 'track'],    $clientWrite);

$sub = new SubscriptionController($config);
$router->get('/api/subscription',        [$sub, 'show'],   $clientGuards);
$router->post('/api/subscription/change', [$sub, 'change'], $clientWrite);

// --- API: global admin console (super_admin only) ---
$adminRead  = [[Guards::class, 'admin']];
$adminWrite = [[Guards::class, 'admin'], [Guards::class, 'csrf']];

$admin = new AdminController($config);
$router->get('/api/admin/metrics',  [$admin, 'metrics'], $adminRead);
$router->get('/api/admin/users',    [$admin, 'users'],   $adminRead);
$router->get('/api/admin/audit',    [$admin, 'audit'],   $adminRead);
$router->post('/api/admin/users/{id}/status',   [$admin, 'setUserStatus'],   $adminWrite);
$router->post('/api/admin/tenants/{id}/tier',   [$admin, 'overrideTier'],    $adminWrite);
$router->post('/api/admin/tenants/{id}/limit',  [$admin, 'overrideLimit'],   $adminWrite);
$router->post('/api/admin/tenants/{id}/status', [$admin, 'setTenantStatus'], $adminWrite);
$router->post('/api/admin/settings',            [$admin, 'updateSettings'],  $adminWrite);

// ---- 5. Dispatch ----------------------------------------------------------
try {
    $router->dispatch(new Request());
} catch (\Throwable $e) {
    if ($config['app']['debug']) {
        Response::error($e->getMessage(), 500, ['trace' => explode("\n", $e->getTraceAsString())]);
    }
    Response::error('Internal server error', 500);
}
