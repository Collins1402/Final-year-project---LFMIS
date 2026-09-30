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

use Lfmis\Core\Database;
use Lfmis\Core\Env;
use Lfmis\Core\Request;
use Lfmis\Core\Response;
use Lfmis\Core\Session;
use Lfmis\Services\AuthService;
use Lfmis\Services\UserService;
use Lfmis\Services\AnimalService;
use Lfmis\Services\HealthService;

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
        Response::json(['user' => $auth->createFirstAdmin($request->body())], 201);
    }
    if ($method === 'POST' && $path === '/api/login') {
        $body = $request->body();
        Response::json(['user' => $auth->login((string) ($body['login'] ?? ''), (string) ($body['password'] ?? ''))]);
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
