<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Env.php';
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Core/Request.php';
require_once __DIR__ . '/../src/Core/Response.php';
require_once __DIR__ . '/../src/Core/Session.php';
require_once __DIR__ . '/../src/Services/AuthService.php';
require_once __DIR__ . '/../src/Services/UserService.php';
require_once __DIR__ . '/../src/Services/AnimalService.php';
require_once __DIR__ . '/../src/Services/HealthService.php';
require_once __DIR__ . '/../src/Services/VerificationService.php';
require_once __DIR__ . '/../src/Services/PasswordResetService.php';
require_once __DIR__ . '/../src/Services/ActivityService.php';
require_once __DIR__ . '/../src/Services/ProductionService.php';
require_once __DIR__ . '/../src/Services/InventoryService.php';
require_once __DIR__ . '/../src/Services/CommerceService.php';
require_once __DIR__ . '/../src/Services/AssistantService.php';
require_once __DIR__ . '/../src/Services/NotificationService.php';

use Lfmis\Core\Database;
use Lfmis\Core\Env;
use Lfmis\Core\Request;
use Lfmis\Core\Response;
use Lfmis\Core\Session;
use Lfmis\Services\AuthService;
use Lfmis\Services\UserService;
use Lfmis\Services\AnimalService;
use Lfmis\Services\HealthService;
use Lfmis\Services\VerificationService;
use Lfmis\Services\PasswordResetService;
use Lfmis\Services\ActivityService;
use Lfmis\Services\ProductionService;
use Lfmis\Services\InventoryService;
use Lfmis\Services\CommerceService;
use Lfmis\Services\AssistantService;
use Lfmis\Services\NotificationService;

Env::load(dirname(__DIR__, 2) . '/.env.local');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, [Env::get('LFMIS_APP_URL', 'http://127.0.0.1:5173'), 'http://localhost:5173'], true)) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
Session::start();
$request = new Request();

try {
    $db = Database::connection();
    $auth = new AuthService($db);
    $users = new UserService($db);
    $animals = new AnimalService($db);
    $health = new HealthService($db);
    $verification = new VerificationService($db);
    $passwordReset = new PasswordResetService($db);
    $activities = new ActivityService($db);
    $production = new ProductionService($db);
    $inventory = new InventoryService($db);
    $commerce = new CommerceService($db);
    $assistant = new AssistantService($db);
    $notifications = new NotificationService($db);
    $method = $request->method();
    $path = $request->path();
    if ($method === 'GET' && $path === '/api/health') {
        $db->query('SELECT 1');
        $mlStatus = 'unavailable';
        $mlUrl = rtrim(Env::get('LFMIS_ML_URL', 'http://127.0.0.1:5000'), '/') . '/health';
        $curl = curl_init($mlUrl);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 1, CURLOPT_TIMEOUT => 2]);
        $mlResponse = curl_exec($curl);
        $mlCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($mlResponse !== false && $mlCode === 200) {
            $mlStatus = 'ok';
        }
        Response::json(['status' => 'ok', 'database' => 'ok', 'ml' => $mlStatus]);
    }
    if ($method === 'GET' && $path === '/api/setup-status') {
        Response::json(['setupRequired' => (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0]);
    }
    if ($method === 'POST' && $path === '/api/setup') {
        $user = $auth->createFirstAdmin($request->body());
        $link = $verification->issue($user['id'], $user['email'], $user['fullName']);
        Response::json([
            'user' => $user,
            'verificationRequired' => true,
            'devVerificationLink' => $link,
            'message' => 'Check the email address to confirm the account before signing in.',
        ], 201);
    }
    if ($method === 'GET' && $path === '/api/verify-email') {
        $token = (string) ($_GET['token'] ?? '');
        Response::json($verification->confirm($token));
    }
    if ($method === 'POST' && $path === '/api/register') {
        $user = $auth->register($request->body());
        $link = $verification->issue($user['id'], $user['email'], $user['fullName']);
        Response::json([
            'user' => $user,
            'verificationRequired' => true,
            'approvalRequired' => in_array($user['role'], ['worker', 'veterinarian'], true),
            'devVerificationLink' => $link,
            'message' => in_array($user['role'], ['worker', 'veterinarian'], true)
                ? 'Confirm your email, then wait for the farm administrator to approve your staff account.'
                : 'Check your email to confirm the account before signing in.',
        ], 201);
    }
    if ($method === 'POST' && $path === '/api/login') {
        $body = $request->body();
        Response::json(['user' => $auth->login((string) ($body['login'] ?? ''), (string) ($body['password'] ?? ''))]);
    }
    if ($method === 'POST' && $path === '/api/password/forgot') {
        $body = $request->body();
        $email = filter_var(trim((string) ($body['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        if ($email === false) {
            Response::error('Enter a valid email address.', 422);
        }
        $link = $passwordReset->request((string) $email);
        $response = ['message' => 'If an active account matches that email, reset instructions have been sent.'];
        if ($link !== null) {
            $response['devResetLink'] = $link;
        }
        Response::json($response);
    }
    if ($method === 'POST' && $path === '/api/password/reset') {
        $body = $request->body();
        $passwordReset->reset((string) ($body['token'] ?? ''), (string) ($body['password'] ?? ''));
        Response::json(['message' => 'Password updated. You can now sign in.']);
    }
    if ($method === 'POST' && $path === '/api/logout') {
        Session::logout();
        Response::json(['ok' => true]);
    }
    if ($method === 'GET' && $path === '/api/session') {
        $user = $auth->currentUser();
        Response::json($user ? ['user' => $user] : ['user' => null], $user ? 200 : 401);
    }
    if ($path === '/api/animals') {
        $current = $auth->currentUser();
        if ($current === null) {
            Response::error('Please sign in to continue.', 401);
        }
        if ($method === 'GET' && in_array($current['role'], ['admin', 'worker', 'veterinarian'], true)) {
            Response::json(['animals' => $animals->list()]);
        }
        if ($method === 'POST' && in_array($current['role'], ['admin', 'worker'], true)) {
            $animal = $animals->create($request->body(), $current['id']);
            $db->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id) VALUES (?, ?, ?, ?)')->execute([$current['id'], 'create', 'animal', $animal['id']]);
            Response::json(['animal' => $animal], 201);
        }
        Response::error('You do not have permission to perform this action.', 403);
    }
    if ($method === 'PATCH' && preg_match('#^/api/animals/(\d+)/status$#', $path, $matches)) {
        $current = $auth->currentUser();
        if ($current === null) {
            Response::error('Please sign in to continue.', 401);
        }
        if (!in_array($current['role'], ['admin', 'worker'], true)) {
            Response::error('You do not have permission to perform this action.', 403);
        }
        $body = $request->body();
        $animal = $animals->updateStatus((int) $matches[1], (string) ($body['status'] ?? ''));
        $db->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id) VALUES (?, ?, ?, ?)')->execute([$current['id'], 'update_status', 'animal', $animal['id']]);
        Response::json(['animal' => $animal]);
    }
    if ($path === '/api/health-records') {
        $current = $auth->currentUser();
        if ($current === null) {
            Response::error('Please sign in to continue.', 401);
        }
        if ($method === 'GET' && in_array($current['role'], ['admin', 'worker', 'veterinarian'], true)) {
            Response::json(['records' => $health->list(), 'dueSoon' => $health->dueSoon()]);
        }
        if ($method === 'POST' && in_array($current['role'], ['admin', 'veterinarian'], true)) {
            $record = $health->create($request->body(), $current['id']);
            $db->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id) VALUES (?, ?, ?, ?)')->execute([$current['id'], 'create', 'health_record', $record['id']]);
            Response::json(['record' => $record], 201);
        }
        Response::error('You do not have permission to perform this action.', 403);
    }
    if ($path === '/api/users') {
        $current = $auth->currentUser();
        if ($current === null) {
            Response::error('Please sign in to continue.', 401);
        }
        if ($current['role'] !== 'admin') {
            Response::error('You do not have permission to perform this action.', 403);
        }
        if ($method === 'GET') {
            Response::json(['users' => $users->list()]);
        }
        if ($method === 'POST') {
            $created = $users->create($request->body());
            $db->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id) VALUES (?, ?, ?, ?)')->execute([$current['id'], 'create', 'user', $created['id']]);
            Response::json(['user' => $created], 201);
        }
    }
    if ($method === 'PATCH' && preg_match('#^/api/users/(\d+)$#', $path, $matches)) {
        $current = $auth->currentUser();
        if ($current === null) {
            Response::error('Please sign in to continue.', 401);
        }
        if ($current['role'] !== 'admin') {
            Response::error('You do not have permission to perform this action.', 403);
        }
        $updated = $users->update((int) $matches[1], $request->body());
        $db->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id) VALUES (?, ?, ?, ?)')->execute([$current['id'], 'update', 'user', $updated['id']]);
        Response::json(['user' => $updated]);
    }
    if ($path === '/api/activities' || preg_match('#^/api/activities/(\d+)/complete$#', $path, $matches)) {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        if ($method === 'GET' && $path === '/api/activities' && in_array($current['role'], ['admin', 'worker', 'veterinarian'], true)) {
            Response::json(['activities' => $activities->list($current['id'], $current['role'])]);
        }
        if ($method === 'POST' && $path === '/api/activities' && in_array($current['role'], ['admin', 'worker'], true)) {
            $item = $activities->create($request->body(), $current['id']);
            $db->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id) VALUES (?, ?, ?, ?)')->execute([$current['id'], 'create', 'activity', $item['id']]);
            Response::json(['activity' => $item], 201);
        }
        if ($method === 'PATCH' && isset($matches[1]) && in_array($current['role'], ['admin', 'worker'], true)) {
            $item = $activities->complete((int) $matches[1], $current['id']);
            $db->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id) VALUES (?, ?, ?, ?)')->execute([$current['id'], 'complete', 'activity', $item['id']]);
            Response::json(['activity' => $item]);
        }
        Response::error('You do not have permission to perform this action.', 403);
    }
    if ($path === '/api/production/milk' || $path === '/api/production/eggs') {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        $allowedRead = in_array($current['role'], ['admin', 'worker'], true);
        if ($method === 'GET' && $allowedRead) Response::json([$path === '/api/production/milk' ? 'milk' : 'eggs' => $path === '/api/production/milk' ? $production->milk() : $production->eggs()]);
        if ($method === 'POST' && $allowedRead) {
            $item = $path === '/api/production/milk' ? $production->recordMilk($request->body(), $current['id']) : $production->recordEggs($request->body(), $current['id']);
            $db->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id) VALUES (?, ?, ?, ?)')->execute([$current['id'], 'create', $path === '/api/production/milk' ? 'milk_production' : 'egg_production', $item['id']]);
            Response::json(['production' => $item], 201);
        }
        Response::error('You do not have permission to perform this action.', 403);
    }
    if ($path === '/api/inventory' || $path === '/api/inventory/items' || preg_match('#^/api/inventory/\d+/transactions$#', $path)) {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        if ($method === 'GET' && $path === '/api/inventory' && in_array($current['role'], ['admin', 'worker'], true)) Response::json(['items' => $inventory->list()]);
        if ($method === 'POST' && $path === '/api/inventory/items' && $current['role'] === 'admin') {
            $item = $inventory->create($request->body());
            $db->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id) VALUES (?, ?, ?, ?)')->execute([$current['id'], 'create', 'inventory_item', $item['id']]);
            Response::json(['item' => $item], 201);
        }
        if ($method === 'POST' && preg_match('#^/api/inventory/(\d+)/transactions$#', $path, $matches) && in_array($current['role'], ['admin', 'worker'], true)) {
            $item = $inventory->transact((int) $matches[1], $request->body(), $current['id']);
            $db->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id) VALUES (?, ?, ?, ?)')->execute([$current['id'], 'transaction', 'inventory_item', (int) $matches[1]]);
            Response::json(['item' => $item]);
        }
        Response::error('You do not have permission to perform this action.', 403);
    }
    if ($path === '/api/products' || $path === '/api/store/products') {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        if ($method === 'GET') Response::json(['products' => $commerce->products()]);
        if ($method === 'POST' && $current['role'] === 'admin') Response::json(['product' => $commerce->createProduct($request->body())], 201);
        Response::error('You do not have permission to perform this action.', 403);
    }
    if ($path === '/api/orders') {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        if ($method === 'GET' && in_array($current['role'], ['admin', 'customer', 'buyer'], true)) Response::json(['orders' => $commerce->orders()]);
        if ($method === 'POST' && $current['role'] === 'admin') Response::json(['order' => $commerce->createOrder($request->body(), $current['id'])], 201);
        Response::error('You do not have permission to perform this action.', 403);
    }
    if ($method === 'PATCH' && preg_match('#^/api/orders/(\d+)$#', $path, $matches)) {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        if ($current['role'] !== 'admin') Response::error('You do not have permission to perform this action.', 403);
        Response::json(['order' => $commerce->updateOrder((int) $matches[1], $request->body(), $current['id'])]);
    }
    if ($path === '/api/sales' || $path === '/api/expenses' || $path === '/api/payments') {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        if ($method === 'GET' && $current['role'] === 'admin') {
            if ($path === '/api/sales') Response::json(['sales' => $commerce->sales()]);
            if ($path === '/api/expenses') Response::json(['expenses' => $commerce->expenses()]);
            Response::json(['payments' => $commerce->payments()]);
        }
        if ($method === 'POST' && $current['role'] === 'admin') {
            if ($path === '/api/sales') Response::json(['sale' => $commerce->createSale($request->body(), $current['id'])], 201);
            if ($path === '/api/expenses') Response::json(['expense' => $commerce->createExpense($request->body(), $current['id'])], 201);
            Response::json(['payment' => $commerce->createPayment($request->body(), $current['id'])], 201);
        }
        Response::error('You do not have permission to perform this action.', 403);
    }
    if ($method === 'GET' && $path === '/api/reports/finance') {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        if ($current['role'] !== 'admin') Response::error('You do not have permission to perform this action.', 403);
        Response::json($commerce->finance());
    }
    if ($path === '/api/assistant/chat' && $method === 'POST') {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        if (!in_array($current['role'], ['admin', 'worker', 'veterinarian', 'customer', 'buyer'], true)) Response::error('You do not have permission to use the assistant.', 403);
        Response::json($assistant->answer((string) ($request->body()['message'] ?? ''), $current));
    }
    if ($path === '/api/notifications' && $method === 'GET') {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        if ($current['role'] === 'admin') $notifications->generateForUser($current['id']);
        Response::json(['notifications' => $notifications->list($current['id'])]);
    }
    if ($method === 'PATCH' && preg_match('#^/api/notifications/(\d+)$#', $path, $matches)) {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        $notifications->markRead((int) $matches[1], $current['id']);
        Response::json(['ok' => true]);
    }
    Response::error('Endpoint not found.', 404);
} catch (DomainException $error) {
    Response::error($error->getMessage(), 409);
} catch (InvalidArgumentException $error) {
    Response::error($error->getMessage(), 422);
} catch (RuntimeException $error) {
    Response::error($error->getMessage(), 401);
} catch (Throwable $error) {
    error_log($error->getMessage());
    Response::error('The server could not complete the request.', 500);
}
