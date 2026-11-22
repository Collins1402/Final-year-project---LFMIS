<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Core/Env.php';
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Core/Request.php';
require_once __DIR__ . '/../src/Core/Response.php';
require_once __DIR__ . '/../src/Core/Session.php';
require_once __DIR__ . '/../src/Core/RequestSecurity.php';
require_once __DIR__ . '/../src/Core/DeliveryException.php';
require_once __DIR__ . '/../src/Core/Mailer.php';
require_once __DIR__ . '/../src/Core/Totp.php';
require_once __DIR__ . '/../src/Services/AuthService.php';
require_once __DIR__ . '/../src/Services/RateLimitException.php';
require_once __DIR__ . '/../src/Services/RateLimitService.php';
require_once __DIR__ . '/../src/Services/TwoFactorService.php';
require_once __DIR__ . '/../src/Services/TotpService.php';
require_once __DIR__ . '/../src/Services/UserService.php';
require_once __DIR__ . '/../src/Services/PermissionService.php';
require_once __DIR__ . '/../src/Services/LoginAlertService.php';
require_once __DIR__ . '/../src/Services/TrendsService.php';
require_once __DIR__ . '/../src/Services/SyntheticTrendsService.php';
require_once __DIR__ . '/../src/Services/FarmMetricsService.php';
require_once __DIR__ . '/../src/Jobs/RefreshTrends.php';
require_once __DIR__ . '/../src/Services/AnimalService.php';
require_once __DIR__ . '/../src/Services/HealthService.php';
require_once __DIR__ . '/../src/Services/VerificationService.php';
require_once __DIR__ . '/../src/Services/PasswordResetService.php';
require_once __DIR__ . '/../src/Services/ActivityService.php';
require_once __DIR__ . '/../src/Services/ProductionService.php';
require_once __DIR__ . '/../src/Services/InventoryService.php';
require_once __DIR__ . '/../src/Services/CommerceService.php';
require_once __DIR__ . '/../src/Services/AuditService.php';
require_once __DIR__ . '/../src/Services/BackupException.php';
require_once __DIR__ . '/../src/Services/BackupService.php';
require_once __DIR__ . '/../src/Services/MpesaService.php';
require_once __DIR__ . '/../src/Services/AssistantService.php';
require_once __DIR__ . '/../src/Services/NotificationService.php';
require_once __DIR__ . '/../src/Services/FeedbackService.php';
require_once __DIR__ . '/../src/Services/DemoService.php';
require_once __DIR__ . '/../src/Services/EventService.php';
require_once __DIR__ . '/../src/Services/ForecastUnavailableException.php';
require_once __DIR__ . '/../src/Services/ForecastService.php';
require_once __DIR__ . '/../src/Services/OverviewService.php';
require_once __DIR__ . '/../src/Services/ReportService.php';
require_once __DIR__ . '/../src/Services/AnalyticsService.php';
require_once __DIR__ . '/../src/Services/AnalyticsAccessException.php';

use Lfmis\Core\Database;
use Lfmis\Core\DeliveryException;
use Lfmis\Core\Env;
use Lfmis\Core\Request;
use Lfmis\Core\RequestSecurity;
use Lfmis\Core\Response;
use Lfmis\Core\Session;
use Lfmis\Services\AuthService;
use Lfmis\Services\UserService;
use Lfmis\Services\PermissionService;
use Lfmis\Services\LoginAlertService;
use Lfmis\Services\TrendsService;
use Lfmis\Services\SyntheticTrendsService;
use Lfmis\Services\FarmMetricsService;
use Lfmis\Jobs\RefreshTrends;
use Lfmis\Services\AnimalService;
use Lfmis\Services\HealthService;
use Lfmis\Services\VerificationService;
use Lfmis\Services\PasswordResetService;
use Lfmis\Services\ActivityService;
use Lfmis\Services\ProductionService;
use Lfmis\Services\InventoryService;
use Lfmis\Services\CommerceService;
use Lfmis\Services\AuditService;
use Lfmis\Services\BackupService;
use Lfmis\Services\BackupException;
use Lfmis\Services\MpesaService;
use Lfmis\Services\AssistantService;
use Lfmis\Services\NotificationService;
use Lfmis\Services\FeedbackService;
use Lfmis\Services\DemoService;
use Lfmis\Services\EventService;
use Lfmis\Services\ForecastService;
use Lfmis\Services\ForecastUnavailableException;
use Lfmis\Services\OverviewService;
use Lfmis\Services\ReportService;
use Lfmis\Services\AnalyticsService;
use Lfmis\Services\AnalyticsAccessException;
use Lfmis\Services\RateLimitException;
use Lfmis\Services\RateLimitService;
use Lfmis\Services\TwoFactorService;
use Lfmis\Services\TotpService;

Env::load(dirname(__DIR__, 2) . '/.env.local');
RequestSecurity::sendHeaders();
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (RequestSecurity::isTrustedOrigin($origin ?: null, $_SERVER['HTTP_REFERER'] ?? null, Env::get('LFMIS_APP_URL', 'http://127.0.0.1:5173'))) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
Session::start();
$request = new Request();
$writeMethod = in_array($request->method(), ['POST', 'PATCH', 'DELETE'], true);
if ($writeMethod && $request->path() !== '/api/payments/mpesa/callback' && Session::userId() !== null
    && !RequestSecurity::isTrustedOrigin($_SERVER['HTTP_ORIGIN'] ?? null, $_SERVER['HTTP_REFERER'] ?? null, Env::get('LFMIS_APP_URL', 'http://127.0.0.1:5173'))
    && !RequestSecurity::isTrustedOrigin($_SERVER['HTTP_ORIGIN'] ?? null, $_SERVER['HTTP_REFERER'] ?? null, 'http://localhost:5173')) {
    Response::error('This request origin could not be verified. Reload the page and try again.', 403);
}

try {
    $db = Database::connection();
    $auth = new AuthService($db);
    $rateLimits = new RateLimitService($db);
    $twoFactor = new TwoFactorService($db);
    $totp = new TotpService($db);
    $users = new UserService($db);
    $permissions = new PermissionService($db);
    $analytics = new AnalyticsService($db, $permissions);
    $loginAlerts = new LoginAlertService();
    $trends = new TrendsService($db);
    $synthetic = new SyntheticTrendsService($db);
    $farmMetrics = new FarmMetricsService($db);
    $refreshTrends = new RefreshTrends($db, $trends, $synthetic, $farmMetrics);
    $animals = new AnimalService($db);
    $health = new HealthService($db);
    $verification = new VerificationService($db);
    $passwordReset = new PasswordResetService($db);
    $activities = new ActivityService($db);
    $production = new ProductionService($db);
    $inventory = new InventoryService($db);
    $commerce = new CommerceService($db);
    $audit = new AuditService($db);
    $backup = new BackupService(dirname(__DIR__, 2), null, $db);
    $mpesa = new MpesaService($db);
    $assistant = new AssistantService($db, $analytics);
    $notifications = new NotificationService($db);
    $feedback = new FeedbackService($db);
    $demo = new DemoService($db);
    $events = new EventService($db);
    $forecast = new ForecastService($db);
    $overview = new OverviewService($db);
    $reports = new ReportService($db);
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
    if ($method === 'GET' && $path === '/api/trends') {
        $live = $trends->current();
        $syntheticData = $synthetic->latest();
        $farmData = $farmMetrics->summary();
        $status = $refreshTrends->status();

        Response::json([
            'kenya' => $live['kenya'] ?? $live['regional'] ?? [],
            'east_africa' => $live['east_africa'] ?? [],
            'global' => $live['global'] ?? [],
            'regional' => $live['regional'] ?? $live['kenya'] ?? [],
            'region' => $live['region'] ?? 'Kenya',
            'refreshHours' => 6,
            'source' => 'GDELT Project news data',
            'synthetic' => $syntheticData,
            'actualFarm' => $farmData,
            'status' => $status,
            'updatedAt' => $live['updatedAt'] ?? gmdate('Y-m-d H:i:s'),
        ]);
    }
    if ($method === 'GET' && $path === '/api/trends/history') {
        $scope = (string) ($_GET['scope'] ?? 'all');
        $type = (string) ($_GET['type'] ?? 'all');
        $limit = min(100, max(1, (int) ($_GET['limit'] ?? 20)));

        $conditions = [];
        $params = [];
        if ($scope !== 'all') {
            $conditions[] = 'geographic_scope = ?';
            $params[] = $scope;
        }
        if ($type !== 'all') {
            $conditions[] = 'trend_type = ?';
            $params[] = $type;
        }
        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        try {
            $stmt = $db->prepare("SELECT id, trend_type, geographic_scope, period_start, period_end, payload, status, created_at FROM trend_snapshot_history {$where} ORDER BY period_start DESC, id DESC LIMIT {$limit}");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['payload'] = json_decode((string) $r['payload'], true) ?: [];
            }
            Response::json(['history' => $rows]);
        } catch (\Throwable) {
            Response::json(['history' => []]);
        }
    }
    if ($method === 'GET' && $path === '/api/trends/metrics') {
        $scope = (string) ($_GET['scope'] ?? 'kenya');
        $category = isset($_GET['category']) ? (string) $_GET['category'] : null;
        $metricName = isset($_GET['metricName']) ? (string) $_GET['metricName'] : null;
        $limit = min(200, max(1, (int) ($_GET['limit'] ?? 40)));
        Response::json(['metrics' => $synthetic->historyMetrics($scope, $category, $metricName, $limit)]);
    }
    if ($method === 'GET' && $path === '/api/trends/status') {
        Response::json($refreshTrends->status());
    }
    if ($method === 'POST' && $path === '/api/trends/refresh') {
        $current = $auth->currentUser();
        if ($current === null) {
            Response::error('Please sign in to continue.', 401);
        }
        if (!$permissions->allows($current['role'], 'trends.manage') && $current['role'] !== 'admin') {
            Response::error('Only administrators can refresh agricultural trends.', 403);
        }
        $rateLimits->consume('trends.refresh', 5, 60, (string) $current['id']);
        $result = $refreshTrends->run(true);
        $audit->record((int) $current['id'], 'trends_refreshed', 'trends', 0, [
            'status' => $result['status'],
            'liveStatus' => $result['liveStatus'] ?? null,
            'syntheticStatus' => $result['syntheticStatus'] ?? null,
        ]);
        Response::json($result);
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
        $rateLimits->consume('login', 10, 60);
        $body = $request->body();
        try {
            $user = $auth->login((string) ($body['login'] ?? ''), (string) ($body['password'] ?? ''), $_COOKIE['lfmis_trusted_device'] ?? null);
        } catch (InvalidArgumentException | RuntimeException $error) {
            $audit->record(null, 'sign_in_failed', 'auth', null, ['reason' => 'credentials_rejected']);
            throw $error;
        }
        if (($user['twoFactorRequired'] ?? false) === true) {
            if (in_array('totp', $user['methods'], true)) {
                $totp->beginLogin((int) $user['pendingUserId']);
                Response::json(['twoFactorRequired' => true, 'methods' => ['totp', 'recovery']]);
            }
            $twoFactor->beginLogin((int) $user['pendingUserId'], $user['methods']);
            Response::json(['twoFactorRequired' => true, 'methods' => $user['methods']]);
        }
        $audit->record((int) $user['id'], 'sign_in_succeeded', 'auth', (int) $user['id']);
        $loginAlerts->notify($user);
        Response::json(['user' => $user, 'mustEnable2fa' => $user['mustEnable2fa'] ?? false]);
    }
    if ($method === 'POST' && $path === '/api/login/2fa') {
        $body = $request->body();
        $result = $totp->verifyPending((string) ($body['code'] ?? ''), (bool) ($body['rememberDevice'] ?? false));
        $user = $auth->completeLogin($result['userId']);
        $audit->record((int) $user['id'], 'sign_in_succeeded', 'auth', (int) $user['id'], ['two_factor' => true, 'recovery_code' => $result['recoveryCodeUsed']]);
        $loginAlerts->notify($user);
        Response::json(['user' => $user, 'mustEnable2fa' => $user['mustEnable2fa'] ?? false]);
    }
    if ($method === 'POST' && $path === '/api/login/2fa/send') {
        $twoFactor->sendLoginCode((string) ($request->body()['method'] ?? ''));
        Response::json(['message' => 'A verification code has been sent.']);
    }
    if ($method === 'POST' && $path === '/api/login/2fa/verify') {
        $body = $request->body();
        try {
            $userId = $twoFactor->verifyLoginCode((string) ($body['method'] ?? ''), (string) ($body['code'] ?? ''));
            $user = $auth->completeLogin($userId);
        } catch (InvalidArgumentException | RuntimeException $error) {
            $audit->record(null, 'sign_in_failed', 'auth', null, ['reason' => 'two_factor_rejected']);
            throw $error;
        }
        $audit->record((int) $user['id'], 'sign_in_succeeded', 'auth', (int) $user['id'], ['two_factor' => true]);
        $loginAlerts->notify($user);
        Response::json(['user' => $user]);
    }
    if ($method === 'POST' && $path === '/api/login/2fa/cancel') {
        $twoFactor->cancelLogin();
        Response::json(['ok' => true]);
    }
    if ($method === 'POST' && $path === '/api/password/forgot') {
        $rateLimits->consume('password.forgot', 5, 3600);
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
    if ($path === '/api/account/2fa') {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        if (!$permissions->allows($current['role'], 'account.security')) Response::error('You do not have permission to manage account security.', 403);
        if ($method === 'GET') {
            Response::json($totp->status($current['id']));
        }
    }
    if ($method === 'POST' && $path === '/api/account/2fa/setup') {
        $current = $auth->currentUser(); if ($current === null) Response::error('Please sign in to continue.', 401);
        if (!$permissions->allows($current['role'], 'account.security')) Response::error('You do not have permission to manage account security.', 403);
        Response::json($totp->beginSetup((int) $current['id']));
    }
    if ($method === 'POST' && $path === '/api/account/2fa/enable') {
        $current = $auth->currentUser(); if ($current === null) Response::error('Please sign in to continue.', 401);
        if (!$permissions->allows($current['role'], 'account.security')) Response::error('You do not have permission to manage account security.', 403);
        Response::json($totp->enable((int) $current['id'], (string) ($request->body()['code'] ?? '')));
    }
    if ($method === 'POST' && $path === '/api/account/2fa/disable') {
        $current = $auth->currentUser(); if ($current === null) Response::error('Please sign in to continue.', 401);
        if (!$permissions->allows($current['role'], 'account.security')) Response::error('You do not have permission to manage account security.', 403);
        $body = $request->body(); $totp->disable((int) $current['id'], (string) ($body['password'] ?? ''), (string) ($body['code'] ?? ''));
        Response::json($totp->status((int) $current['id']));
    }
    if ($method === 'POST' && $path === '/api/account/2fa/recovery-codes') {
        $current = $auth->currentUser(); if ($current === null) Response::error('Please sign in to continue.', 401);
        if (!$permissions->allows($current['role'], 'account.security')) Response::error('You do not have permission to manage account security.', 403);
        $body = $request->body();
        Response::json(['recoveryCodes' => $totp->regenerateRecoveryCodes((int) $current['id'], (string) ($body['password'] ?? ''), (string) ($body['code'] ?? ''))]);
    }
    if ($method === 'POST' && $path === '/api/account/2fa/trusted-devices/revoke') {
        $current = $auth->currentUser(); if ($current === null) Response::error('Please sign in to continue.', 401);
        if (!$permissions->allows($current['role'], 'account.security')) Response::error('You do not have permission to manage account security.', 403);
        $totp->revokeAllDevices((int) $current['id']);
        Response::json($totp->status((int) $current['id']));
    }
    if ($method === 'POST' && $path === '/api/account/2fa/enroll') {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        $body = $request->body();
        $twoFactor->startEnrollment($current['id'], (string) ($body['method'] ?? ''));
        Response::json(['message' => 'A verification code has been sent. Enter it to enable this method.']);
    }
    if ($method === 'POST' && $path === '/api/account/2fa/enroll/verify') {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        $body = $request->body();
        $twoFactor->completeEnrollment($current['id'], (string) ($body['method'] ?? ''), (string) ($body['code'] ?? ''));
        Response::json($twoFactor->status($current['id']));
    }
    if ($method === 'POST' && $path === '/api/account/2fa/remove') {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        $body = $request->body();
        $twoFactor->removeMethod($current['id'], (string) ($body['method'] ?? ''), (string) ($body['password'] ?? ''));
        Response::json($twoFactor->status($current['id']));
    }
    if ($path === '/api/animals') {
        $current = $auth->currentUser();
        if ($current === null) {
            Response::error('Please sign in to continue.', 401);
        }
        if ($method === 'GET' && $permissions->allows($current['role'], 'animals.view')) {
            Response::json(['animals' => $animals->list()]);
        }
        if ($method === 'POST' && $permissions->allows($current['role'], 'animals.create')) {
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
        if (!$permissions->allows($current['role'], 'animals.status')) {
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
        if ($method === 'GET' && $permissions->allows($current['role'], 'health.view')) {
            Response::json(['records' => $health->list(), 'dueSoon' => $health->dueSoon()]);
        }
        if ($method === 'POST' && $permissions->allows($current['role'], 'health.record')) {
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
        if (!$permissions->allows($current['role'], 'users.manage')) {
            Response::error('You do not have permission to perform this action.', 403);
        }
        if ($method === 'GET') {
            Response::json(['users' => $users->list()]);
        }
        if ($method === 'POST') {
            $created = $users->create($request->body());
            $audit->record((int) $current['id'], 'user_created', 'user', (int) $created['id'], ['role' => $created['role']]);
            Response::json(['user' => $created], 201);
        }
    }
    if ($method === 'PATCH' && preg_match('#^/api/users/(\d+)$#', $path, $matches)) {
        $current = $auth->currentUser();
        if ($current === null) {
            Response::error('Please sign in to continue.', 401);
        }
        if (!$permissions->allows($current['role'], 'users.manage')) {
            Response::error('You do not have permission to perform this action.', 403);
        }
        $body = $request->body();
        $updated = $users->update((int) $matches[1], $body);
        $action = array_key_exists('isActive', $body) && filter_var($body['isActive'], FILTER_VALIDATE_BOOLEAN) === false
            ? 'user_deactivated'
            : 'user_updated';
        $audit->record((int) $current['id'], $action, 'user', (int) $updated['id'], ['role' => $updated['role']]);
        Response::json(['user' => $updated]);
    }
    if ($method === 'POST' && preg_match('#^/api/users/(\d+)/reset-2fa$#', $path, $matches)) {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        if (!$permissions->allows($current['role'], 'users.manage')) Response::error('You do not have permission to perform this action.', 403);
        $targetId = (int) $matches[1];
        $targetQuery = $db->prepare('SELECT email FROM users WHERE id = ?');
        $targetQuery->execute([$targetId]);
        $targetEmail = $targetQuery->fetchColumn();
        if (!is_string($targetEmail)) Response::error('User account not found.', 404);
        $db->beginTransaction();
        try {
            $db->prepare('UPDATE users SET totp_secret_enc = NULL, totp_enabled_at = NULL, totp_last_step = 0 WHERE id = ?')->execute([$targetId]);
            $db->prepare('DELETE FROM recovery_codes WHERE user_id = ?')->execute([$targetId]);
            $totp->revokeDevices($targetId);
            $db->commit();
        } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
        $audit->record((int) $current['id'], 'user_two_factor_reset', 'user', $targetId);
        \Lfmis\Core\Mailer::send($targetEmail, 'LFMIS two-step verification reset', 'An administrator reset two-step verification for your LFMIS account. Set it up again the next time you sign in.');
        Response::json(['ok' => true]);
    }
    if ($method === 'DELETE' && preg_match('#^/api/users/(\d+)$#', $path, $matches)) {
        $current = $auth->currentUser();
        if ($current === null) {
            Response::error('Please sign in to continue.', 401);
        }
        if (!$permissions->allows($current['role'], 'users.manage')) {
            Response::error('You do not have permission to perform this action.', 403);
        }
        $userId = (int) $matches[1];
        $users->delete($userId, (int) $current['id']);
        $db->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id) VALUES (?, ?, ?, ?)')->execute([$current['id'], 'delete', 'user', $userId]);
        Response::json(['ok' => true]);
    }
    if ($path === '/api/permissions' && $method === 'GET') {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        if ($permissions->allows($current['role'], 'roles.manage')) {
            Response::json(['role' => $current['role'], 'matrix' => $permissions->matrix()]);
        }
        $matrix = $permissions->matrix();
        Response::json(['role' => $current['role'], 'permissions' => $matrix[$current['role']] ?? []]);
    }
    if ($method === 'POST' && preg_match('#^/api/permissions/([a-z]+)$#', $path, $matches)) {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        if (!$permissions->allows($current['role'], 'roles.manage')) Response::error('You do not have permission to perform this action.', 403);
        $matrix = $permissions->updateRole($matches[1], (array) ($request->body()['permissions'] ?? []));
        $audit->record((int) $current['id'], 'role_permissions_changed', 'role', null, ['role' => $matches[1]]);
        Response::json(['permissions' => $matrix]);
    }
    if ($method === 'GET' && $path === '/api/audit-logs') {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        if (!$permissions->allows($current['role'], 'audit.view')) Response::error('You do not have permission to perform this action.', 403);
        Response::json(['logs' => $audit->list([
            'userId' => $_GET['userId'] ?? '',
            'action' => $_GET['action'] ?? '',
            'from' => $_GET['from'] ?? '',
            'to' => $_GET['to'] ?? '',
        ])]);
    }
    if ($method === 'GET' && $path === '/api/admin/export') {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        if (!$permissions->allows($current['role'], 'data.export')) Response::error('You do not have permission to perform this action.', 403);
        $export = [
            'animals' => $db->query('SELECT id, tag_number, species, breed, name, sex, date_of_birth, weight_kg, status, notes, created_at FROM animals')->fetchAll(),
            'health_records' => $db->query('SELECT id, animal_id, record_type, description, medication, dosage, cost, record_date, next_due_date, created_at FROM health_records')->fetchAll(),
            'farm_activities' => $db->query('SELECT id, activity_type, description, animal_id, assigned_to, activity_at, status, completed_at, created_at FROM farm_activities')->fetchAll(),
            'farm_produce' => $db->query('SELECT id, produce_name, production_date, production_session, animal_id, quantity_produced, quantity_sold, quantity_damaged, unit, notes, created_at FROM farm_produce')->fetchAll(),
            'inventory_items' => $db->query('SELECT id, name, category, unit, quantity_on_hand, reorder_level, unit_cost, is_active FROM inventory_items')->fetchAll(),
            'orders' => $db->query('SELECT id, customer_id, status, total_amount, notes, ordered_at, accepted_at, fulfilled_at FROM orders')->fetchAll(),
            'order_items' => $db->query('SELECT id, order_id, product_id, item_name, quantity, unit, unit_price FROM order_items')->fetchAll(),
            'payments' => $db->query('SELECT id, order_id, customer_id, amount, method, status, transaction_reference, checkout_request_id, phone, paid_at, created_at FROM payments')->fetchAll(),
            'sales' => $db->query('SELECT id, order_id, customer_id, description, quantity, unit, unit_price, sold_at FROM sales')->fetchAll(),
            'expenses' => $db->query('SELECT id, category, description, amount, expense_date FROM expenses')->fetchAll(),
        ];
        $audit->record((int) $current['id'], 'data_exported', 'export', null, ['tables' => array_keys($export)]);
        Response::json(['generatedAt' => date(DATE_ATOM), 'data' => $export]);
    }
    if ($method === 'POST' && $path === '/api/admin/backup') {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        if (!$permissions->allows($current['role'], 'backup.create')) Response::error('You do not have permission to perform this action.', 403);
        $created = $backup->create();
        $audit->record((int) $current['id'], 'backup_created', 'backup', null, ['filename' => $created['filename']]);
        Response::json(['backup' => $created], 201);
    }
    if ($method === 'GET' && $path === '/api/events') {
        Response::json(['events' => $events->publicList()]);
    }
    if ($path === '/api/events/admin') {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        if (!$permissions->allows($current['role'], 'events.manage')) Response::error('You do not have permission to perform this action.', 403);
        if ($method === 'GET') Response::json(['events' => $events->adminList()]);
        Response::error('Method not allowed.', 405);
    }
    if ($method === 'POST' && $path === '/api/events') {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        if (!$permissions->allows($current['role'], 'events.manage')) Response::error('You do not have permission to perform this action.', 403);
        if ($method === 'POST') {
            $event = $events->create($request->body(), $current['id']);
            $db->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id) VALUES (?, ?, ?, ?)')->execute([$current['id'], 'create', 'event', $event['id']]);
            Response::json(['event' => $event], 201);
        }
    }
    if ($method === 'PATCH' && preg_match('#^/api/events/(\d+)$#', $path, $matches)) {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        if (!$permissions->allows($current['role'], 'events.manage')) Response::error('You do not have permission to perform this action.', 403);
        $body = $request->body();
        $event = isset($body['status']) && count($body) === 1
            ? $events->setStatus((int) $matches[1], (string) $body['status'])
            : $events->update((int) $matches[1], $body);
        $db->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id) VALUES (?, ?, ?, ?)')->execute([$current['id'], 'update', 'event', $event['id']]);
        Response::json(['event' => $event]);
    }
    if ($path === '/api/activities' || preg_match('#^/api/activities/(\d+)/complete$#', $path, $matches)) {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        if ($method === 'GET' && $path === '/api/activities' && $permissions->allows($current['role'], 'activities.view')) {
            Response::json(['activities' => $activities->list($current['id'], $current['role'])]);
        }
        if ($method === 'POST' && $path === '/api/activities' && $permissions->allows($current['role'], 'activities.create')) {
            $item = $activities->create($request->body(), $current['id']);
            $db->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id) VALUES (?, ?, ?, ?)')->execute([$current['id'], 'create', 'activity', $item['id']]);
            Response::json(['activity' => $item], 201);
        }
        if ($method === 'PATCH' && isset($matches[1]) && $permissions->allows($current['role'], 'activities.complete')) {
            $item = $activities->complete((int) $matches[1], $current['id']);
            $db->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id) VALUES (?, ?, ?, ?)')->execute([$current['id'], 'complete', 'activity', $item['id']]);
            Response::json(['activity' => $item]);
        }
        Response::error('You do not have permission to perform this action.', 403);
    }
    if ($path === '/api/production' || $path === '/api/production/summary') {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        if ($method === 'GET' && $path === '/api/production/summary' && $permissions->allows($current['role'], 'production.view')) Response::json($production->summary());
        if ($method === 'GET' && $permissions->allows($current['role'], 'production.view')) Response::json(['records' => $production->records()]);
        if ($method === 'POST' && $permissions->allows($current['role'], 'production.record')) {
            $record = $production->record($request->body(), $current['id']);
            $db->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id) VALUES (?, ?, ?, ?)')->execute([$current['id'], 'create', 'farm_produce', $record['id']]);
            Response::json(['record' => $record], 201);
        }
        Response::error('You do not have permission to perform this action.', 403);
    }
    if ($path === '/api/inventory' || $path === '/api/inventory/items' || $path === '/api/inventory/transactions' || preg_match('#^/api/inventory/\d+/transactions$#', $path)) {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        if ($method === 'GET' && $path === '/api/inventory' && $permissions->allows($current['role'], 'inventory.view')) Response::json(['items' => $inventory->list()]);
        if ($method === 'GET' && $path === '/api/inventory/transactions' && $permissions->allows($current['role'], 'inventory.view')) Response::json(['transactions' => $inventory->transactions()]);
        if ($method === 'POST' && $path === '/api/inventory/items' && $permissions->allows($current['role'], 'inventory.manage')) {
            $item = $inventory->create($request->body());
            $db->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id) VALUES (?, ?, ?, ?)')->execute([$current['id'], 'create', 'inventory_item', $item['id']]);
            Response::json(['item' => $item], 201);
        }
        if ($method === 'POST' && preg_match('#^/api/inventory/(\d+)/transactions$#', $path, $matches) && $permissions->allows($current['role'], 'inventory.transact')) {
            $item = $inventory->transact((int) $matches[1], $request->body(), $current['id']);
            $db->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id) VALUES (?, ?, ?, ?)')->execute([$current['id'], 'transaction', 'inventory_item', (int) $matches[1]]);
            Response::json(['item' => $item]);
        }
        Response::error('You do not have permission to perform this action.', 403);
    }
    if ($path === '/api/products' || $path === '/api/store/products') {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        if ($method === 'GET' && $permissions->allows($current['role'], 'products.view')) Response::json(['products' => $commerce->products($current['role'])]);
        if ($method === 'POST' && $permissions->allows($current['role'], 'products.manage')) Response::json(['product' => $commerce->createProduct($request->body())], 201);
        Response::error('You do not have permission to perform this action.', 403);
    }
    if ($method === 'POST' && $path === '/api/payments/mpesa/callback') {
        if (Env::get('LFMIS_MPESA_DEMO_MODE', '0') === '1') {
            Response::json(['ResultCode' => 0, 'ResultDesc' => 'Demo mode is active; callback ignored.']);
        }
        $result = $mpesa->handleCallback((string) ($_GET['token'] ?? ''), $request->body());
        Response::json([
            'ResultCode' => 0,
            'ResultDesc' => !empty($result['duplicate']) ? 'Duplicate callback acknowledged.' : 'Callback processed.',
        ]);
    }
    if ($method === 'POST' && $path === '/api/payments/mpesa/initiate') {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        $allowed = $permissions->allows($current['role'], 'orders.create')
            || $permissions->allows($current['role'], 'orders.manage')
            || $permissions->allows($current['role'], 'finance.manage');
        if (!$allowed) Response::error('You do not have permission to perform this action.', 403);
        if (Env::get('LFMIS_MPESA_DEMO_MODE', '0') === '1') {
            Response::error('M-PESA demo mode is active; no payment request was sent.', 409);
        }
        $rateLimits->consume('payments.mpesa.initiate', 5, 60, (string) $current['id'] . ':' . (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $body = $request->body();
        $orderId = filter_var($body['orderId'] ?? null, FILTER_VALIDATE_INT);
        if ($orderId === false || $orderId === null) Response::error('Choose a valid order.', 422);
        Response::json(['payment' => $mpesa->initiate((int) $orderId, $current, isset($body['phone']) ? (string) $body['phone'] : null)], 201);
    }
    if ($path === '/api/orders') {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        if ($method === 'GET' && $permissions->allows($current['role'], 'orders.view')) Response::json([
            'orders' => $commerce->orders($current['role'] === 'admin' ? null : (int) $current['id']),
            'mpesaDemoMode' => Env::get('LFMIS_MPESA_DEMO_MODE', '0') === '1',
        ]);
        if ($method === 'POST' && $permissions->allows($current['role'], 'orders.manage')) Response::json(['order' => $commerce->createOrder($request->body(), $current['id'])], 201);
        if ($method === 'POST' && $permissions->allows($current['role'], 'orders.create')) {
            $order = $commerce->createCustomerOrder($request->body(), $current);
            $db->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id) VALUES (?, ?, ?, ?)')->execute([$current['id'], 'create', 'order', $order['id']]);
            Response::json(['order' => $order], 201);
        }
        Response::error('You do not have permission to perform this action.', 403);
    }
    if ($method === 'PATCH' && preg_match('#^/api/orders/(\d+)$#', $path, $matches)) {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        if (!$permissions->allows($current['role'], 'orders.manage')) Response::error('You do not have permission to perform this action.', 403);
        $orderId = (int) $matches[1];
        $before = $db->prepare('SELECT status FROM orders WHERE id = ?');
        $before->execute([$orderId]);
        $previousStatus = $before->fetchColumn();
        $order = $commerce->updateOrder($orderId, $request->body(), $current['id']);
        if ($previousStatus !== $order['status']) {
            $audit->record((int) $current['id'], 'order_status_changed', 'order', $orderId, [
                'from' => $previousStatus,
                'to' => $order['status'],
            ]);
        }
        Response::json(['order' => $order]);
    }
    if ($path === '/api/sales' || $path === '/api/expenses' || $path === '/api/payments') {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        if ($method === 'GET' && $permissions->allows($current['role'], 'finance.view')) {
            if ($path === '/api/sales') Response::json(['sales' => $commerce->sales()]);
            if ($path === '/api/expenses') Response::json(['expenses' => $commerce->expenses()]);
            Response::json(['payments' => $commerce->payments()]);
        }
        if ($method === 'GET' && $path === '/api/payments' && $permissions->allows($current['role'], 'payments.view')) {
            Response::json(['payments' => $commerce->payments((int) $current['id'])]);
        }
        if ($method === 'POST' && $permissions->allows($current['role'], 'finance.manage')) {
            if ($path === '/api/sales') Response::json(['sale' => $commerce->createSale($request->body(), $current['id'])], 201);
            if ($path === '/api/expenses') Response::json(['expense' => $commerce->createExpense($request->body(), $current['id'])], 201);
            Response::json(['payment' => $commerce->createPayment($request->body(), $current['id'])], 201);
        }
        Response::error('You do not have permission to perform this action.', 403);
    }
    if ($method === 'GET' && $path === '/api/reports/finance') {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        if (!$permissions->allows($current['role'], 'reports.finance')) Response::error('You do not have permission to perform this action.', 403);
        Response::json($commerce->finance());
    }
    if ($method === 'GET' && $path === '/api/overview') {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        if (!$permissions->allows($current['role'], 'reports.operations')) Response::error('You do not have permission to perform this action.', 403);
        Response::json($overview->summary());
    }
    if ($method === 'GET' && $path === '/api/reports/forecast') {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        if (!$permissions->allows($current['role'], 'reports.forecast')) Response::error('You do not have permission to perform this action.', 403);
        Response::json($forecast->report((int) $current['id']));
    }
    if ($method === 'GET' && $path === '/api/reports/operations') {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        if (!$permissions->allows($current['role'], 'reports.forecast')) Response::error('You do not have permission to perform this action.', 403);
        Response::json($reports->summary());
    }
    if ($method === 'GET' && $path === '/api/reports/forecast/history') {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        if ($current['role'] !== 'admin') Response::error('You do not have permission to perform this action.', 403);
        Response::json(['runs' => $forecast->history()]);
    }
    if ($method === 'GET' && preg_match('#^/api/analytics/([a-z_]+)$#', $path, $matches)) {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        $chart = $analytics->series($matches[1], (string)($_GET['period'] ?? '30d'), isset($_GET['groupBy']) ? (string)$_GET['groupBy'] : null, isset($_GET['type']) ? (string)$_GET['type'] : null, (string)$current['role']);
        Response::json($chart);
    }
    if ($path === '/api/assistant/chat' && $method === 'POST') {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        if (!$permissions->allows($current['role'], 'assistant.use')) Response::error('You do not have permission to use the assistant.', 403);
        $question = (string) ($request->body()['message'] ?? '');
        $trendContext = preg_match('/\b(trend|worldwide|global|current news)\b/i', $question) ? $trends->current() : [];
        Response::json($assistant->answer($question, $current, $trendContext));
    }
    if ($path === '/api/assistant/scenario' && $method === 'POST') {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        if (!$permissions->allows($current['role'], 'scenarios.run')) Response::error('You do not have permission to run yield scenarios.', 403);
        $body = $request->body();
        $additionalCows = filter_var($body['additionalCows'] ?? 0, FILTER_VALIDATE_INT);
        $additionalHens = filter_var($body['additionalHens'] ?? 0, FILTER_VALIDATE_INT);
        if ($additionalCows === false || $additionalHens === false) Response::error('Additional animal counts must be whole numbers.', 422);
        Response::json($forecast->scenario($additionalCows, $additionalHens));
    }
    if ($path === '/api/demo-data' && $method === 'POST') {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        if (!$permissions->allows($current['role'], 'demo.manage')) Response::error('Only an administrator can load demo data.', 403);
        Response::json($demo->seed($current['id']), 201);
    }
    if ($path === '/api/notifications' && $method === 'GET') {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        if (!$permissions->allows($current['role'], 'notifications.view')) Response::error('You do not have permission to perform this action.', 403);
        if ($current['role'] === 'admin') $notifications->generateForUser($current['id']);
        Response::json(['notifications' => $notifications->list($current['id'])]);
    }
    if ($method === 'PATCH' && preg_match('#^/api/notifications/(\d+)$#', $path, $matches)) {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        $notifications->markRead((int) $matches[1], $current['id']);
        Response::json(['ok' => true]);
    }
    if ($path === '/api/feedback') {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        if ($method === 'GET' && $permissions->allows($current['role'], 'feedback.view')) Response::json(['feedback' => $feedback->list($current['role'] === 'admin' ? null : (int) $current['id'])]);
        if ($method === 'POST' && $permissions->allows($current['role'], 'feedback.create')) Response::json(['feedback' => $feedback->create($request->body(), $current['id'])], 201);
        Response::error('You do not have permission to perform this action.', 403);
    }
    if ($method === 'PATCH' && preg_match('#^/api/feedback/(\d+)/resolve$#', $path, $matches)) {
        $current = $auth->currentUser();
        if ($current === null) Response::error('Please sign in to continue.', 401);
        if (!$permissions->allows($current['role'], 'feedback.resolve')) Response::error('You do not have permission to perform this action.', 403);
        $feedback->resolve((int)$matches[1], $current['id']); Response::json(['ok'=>true]);
    }
    Response::error('Endpoint not found.', 404);
} catch (DomainException $error) {
    Response::error($error->getMessage(), 409);
} catch (InvalidArgumentException $error) {
    Response::error($error->getMessage(), 422);
} catch (RateLimitException $error) {
    Response::error($error->getMessage(), 429);
} catch (DeliveryException $error) {
    Response::error($error->getMessage(), 503);
} catch (ForecastUnavailableException $error) {
    Response::error($error->getMessage(), 503);
} catch (BackupException $error) {
    Response::error($error->getMessage(), 503);
} catch (AnalyticsAccessException $error) {
    Response::error($error->getMessage(), 403);
} catch (RuntimeException $error) {
    Response::error($error->getMessage(), 401);
} catch (Throwable $error) {
    error_log($error->getMessage());
    Response::error('The server could not complete the request.', 500);
}
