<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mail.php';

$allowedOrigins = ['http://127.0.0.1:5173', 'http://localhost:5173'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}

header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
    'path' => '/',
]);
session_start();

function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

function requestBody(): array
{
    $body = json_decode(file_get_contents('php://input'), true);
    return is_array($body) ? $body : [];
}

function validUsername(mixed $value): ?string
{
    $username = trim((string) $value);
    return preg_match('/^[A-Za-z0-9_.]{3,30}$/', $username) ? $username : null;
}

function appUrl(): string
{
    $appUrl = rtrim(getenv('LFMIS_APP_URL') ?: 'http://127.0.0.1:5173', '/');
    $parts = parse_url($appUrl);
    if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
        throw new RuntimeException('LFMIS_APP_URL must be an HTTP or HTTPS origin.');
    }
    return $appUrl;
}

function verifyOwnPassword(PDO $db, int $userId, string $password): void
{
    $check = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
    $check->execute([$userId]);
    if (!password_verify($password, (string) $check->fetchColumn())) {
        respond(403, ['error' => 'Your current password is incorrect.']);
    }
}

function openAdminInvite(PDO $db, bool $lock = false): array|false
{
    $statement = $db->query(
        "SELECT i.id, i.email, i.status, i.new_role_for_current, i.expires_at, i.confirmed_at,
                i.recipient_user_id, i.created_user, u.full_name AS recipient_name, u.username AS recipient_username
         FROM admin_transfer_invites i LEFT JOIN users u ON u.id = i.recipient_user_id
         WHERE i.status IN ('pending', 'confirmed') AND i.expires_at > CURRENT_TIMESTAMP
         ORDER BY i.id DESC LIMIT 1" . ($lock ? ' FOR UPDATE' : '')
    );
    return $statement->fetch();
}

function closeAdminInvite(PDO $db, array $invite, string $status): void
{
    $db->prepare('UPDATE admin_transfer_invites SET status = ? WHERE id = ?')->execute([$status, $invite['id']]);
    // Remove placeholder accounts that were only created to accept this invite.
    if ($status === 'cancelled' && (int) $invite['created_user'] === 1 && $invite['recipient_user_id'] !== null) {
        $db->prepare('DELETE FROM users WHERE id = ? AND is_active = 0')->execute([$invite['recipient_user_id']]);
    }
}

function requireUser(): array
{
    if (!isset($_SESSION['user']['id'])) {
        respond(401, ['error' => 'Please sign in to continue.']);
    }

    // Reload from the database so role changes, transfers, and deactivation apply immediately.
    $statement = database()->prepare(
        'SELECT users.id, users.full_name, users.email, roles.name AS role
         FROM users JOIN roles ON roles.id = users.role_id
         WHERE users.id = ? AND users.is_active = 1'
    );
    $statement->execute([(int) $_SESSION['user']['id']]);
    $row = $statement->fetch();
    if (!$row) {
        $_SESSION = [];
        respond(401, ['error' => 'Please sign in to continue.']);
    }
    $_SESSION['user'] = [
        'id' => (int) $row['id'], 'fullName' => $row['full_name'], 'email' => $row['email'], 'role' => $row['role'],
    ];

    return $_SESSION['user'];
}

function requireRole(array $user, array $roles): void
{
    if (!in_array($user['role'], $roles, true)) {
        respond(403, ['error' => 'You do not have permission to perform this action.']);
    }
}

function audit(PDO $db, int $userId, string $action, string $entity, ?int $entityId = null): void
{
    $statement = $db->prepare(
        'INSERT INTO audit_logs (user_id, action, entity_type, entity_id) VALUES (?, ?, ?, ?)'
    );
    $statement->execute([$userId, $action, $entity, $entityId]);
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = rtrim(is_string($path) ? $path : '/', '/');
$body = requestBody();

try {
    $db = database();

    if ($method === 'GET' && $path === '/api/setup-status') {
        $users = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
        respond(200, ['setupRequired' => $users === 0]);
    }

    if ($method === 'POST' && $path === '/api/setup') {
        $users = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($users !== 0) {
            respond(409, ['error' => 'Initial setup has already been completed.']);
        }

        $name = trim((string) ($body['fullName'] ?? ''));
        $username = validUsername($body['username'] ?? '');
        $email = filter_var(trim((string) ($body['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $password = (string) ($body['password'] ?? '');
        if ($name === '' || $username === null || $email === false || strlen($password) < 12) {
            respond(422, ['error' => 'Enter a name, a username (3–30 letters, numbers, _ or .), valid email, and password with at least 12 characters.']);
        }

        $roleId = $db->query("SELECT id FROM roles WHERE name = 'admin'")->fetchColumn();
        $statement = $db->prepare(
            'INSERT INTO users (role_id, full_name, username, email, password_hash) VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([$roleId, $name, $username, $email, password_hash($password, PASSWORD_DEFAULT)]);
        $userId = (int) $db->lastInsertId();
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => $userId, 'fullName' => $name, 'email' => $email, 'role' => 'admin'];
        audit($db, $userId, 'initial_setup', 'user', $userId);
        respond(201, ['user' => $_SESSION['user']]);
    }

    if ($method === 'POST' && $path === '/api/login') {
        $login = trim((string) ($body['login'] ?? $body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        if ($login === '' || strlen($login) > 190 || $password === '') {
            respond(422, ['error' => 'Enter your username or email and your password.']);
        }

        $column = str_contains($login, '@') ? 'users.email' : 'users.username';
        $statement = $db->prepare(
            "SELECT users.id, users.full_name, users.email, users.password_hash, users.is_active, roles.name AS role
             FROM users JOIN roles ON roles.id = users.role_id
             WHERE {$column} = ? LIMIT 1"
        );
        $statement->execute([$login]);
        $user = $statement->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            respond(401, ['error' => 'Username/email or password is incorrect.']);
        }
        if (!(int) $user['is_active']) {
            respond(403, ['error' => 'Your account is awaiting approval by the farm administrator.']);
        }

        session_regenerate_id(true);
        unset($user['password_hash'], $user['is_active']);
        $user['id'] = (int) $user['id'];
        $user['fullName'] = $user['full_name'];
        unset($user['full_name']);
        $_SESSION['user'] = $user;
        audit($db, $user['id'], 'login', 'user', $user['id']);
        respond(200, ['user' => $user]);
    }

    if ($method === 'POST' && $path === '/api/password/forgot') {
        $email = filter_var(trim((string) ($body['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        if ($email === false) {
            respond(422, ['error' => 'Enter a valid email address.']);
        }

        $statement = $db->prepare(
            'SELECT id, full_name, email FROM users WHERE email = ? AND is_active = 1 LIMIT 1'
        );
        $statement->execute([$email]);
        $user = $statement->fetch();
        if ($user) {
            $recent = $db->prepare(
                'SELECT 1 FROM password_reset_tokens
                 WHERE user_id = ? AND created_at > DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 MINUTE)'
            );
            $recent->execute([$user['id']]);
            if (!$recent->fetchColumn()) {
                $token = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $token);
                $saveToken = $db->prepare(
                    'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
                     VALUES (?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 30 MINUTE))
                     ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash),
                         expires_at = VALUES(expires_at), created_at = CURRENT_TIMESTAMP'
                );
                $saveToken->execute([$user['id'], $tokenHash]);

                $resetUrl = appUrl() . '/#reset_token=' . rawurlencode($token);
                $subject = 'Reset your Flouri Farm LFMIS password';
                $message = "Hello {$user['full_name']},\n\n"
                    . "Use this link to choose a new password. It expires in 30 minutes and can only be used once:\n\n"
                    . "{$resetUrl}\n\nIf you did not request this change, you can ignore this email.\n";
                $devLogLink = filter_var(getenv('LFMIS_DEV_LOG_PASSWORD_RESET_LINK') ?: 'false', FILTER_VALIDATE_BOOLEAN);
                if ($devLogLink) {
                    error_log("Development password reset link for account {$user['id']}: {$resetUrl}");
                } elseif (!sendMail($email, $subject, $message)) {
                    error_log("Password reset email delivery failed for account {$user['id']}.");
                }
            }
        }

        respond(202, ['message' => 'If an active account uses that email, password reset instructions will be sent.']);
    }

    if ($method === 'POST' && $path === '/api/password/reset') {
        $token = (string) ($body['token'] ?? '');
        $password = (string) ($body['password'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $token) || strlen($password) < 12) {
            respond(422, ['error' => 'Use a valid reset link and a password with at least 12 characters.']);
        }

        $db->beginTransaction();
        $statement = $db->prepare(
            'SELECT user_id FROM password_reset_tokens
             WHERE token_hash = ? AND expires_at > CURRENT_TIMESTAMP FOR UPDATE'
        );
        $statement->execute([hash('sha256', $token)]);
        $userId = $statement->fetchColumn();
        if ($userId === false) {
            $db->rollBack();
            respond(422, ['error' => 'This reset link is invalid or has expired. Request a new one.']);
        }

        $updatePassword = $db->prepare(
            'UPDATE users SET password_hash = ? WHERE id = ? AND is_active = 1'
        );
        $updatePassword->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
        if ($updatePassword->rowCount() === 0) {
            $db->rollBack();
            respond(422, ['error' => 'This reset link is invalid or has expired. Request a new one.']);
        }
        $deleteToken = $db->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?');
        $deleteToken->execute([$userId]);
        audit($db, (int) $userId, 'password_reset', 'user', (int) $userId);
        $db->commit();
        respond(200, ['ok' => true, 'message' => 'Password updated. Sign in with your new password.']);
    }

    if ($method === 'POST' && $path === '/api/register') {
        $name = trim((string) ($body['fullName'] ?? ''));
        $username = validUsername($body['username'] ?? '');
        $email = filter_var(trim((string) ($body['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $password = (string) ($body['password'] ?? '');
        $role = (string) ($body['role'] ?? '');
        if ($role === 'admin') {
            respond(403, ['error' => 'The farm has one administrator. Admin rights can only be transferred by the current admin.']);
        }
        if ($name === '' || strlen($name) > 120 || $username === null || $email === false || strlen($password) < 12
            || !in_array($role, ['customer', 'buyer', 'worker', 'veterinarian'], true)) {
            respond(422, ['error' => 'Enter your name, a username (3–30 letters, numbers, _ or .), a valid email, a password of at least 12 characters, and choose a user type.']);
        }
        // Staff accounts can see farm records, so they stay inactive until the admin approves them.
        $needsApproval = in_array($role, ['worker', 'veterinarian'], true);
        $statement = $db->prepare(
            'INSERT INTO users (role_id, full_name, username, email, password_hash, is_active)
             SELECT id, ?, ?, ?, ?, ? FROM roles WHERE name = ?'
        );
        $statement->execute([$name, $username, $email, password_hash($password, PASSWORD_DEFAULT), $needsApproval ? 0 : 1, $role]);
        if ($statement->rowCount() !== 1) {
            respond(500, ['error' => 'Account roles are missing. Run the database migration.']);
        }
        $userId = (int) $db->lastInsertId();
        audit($db, $userId, 'register', 'user', $userId);
        if ($needsApproval) {
            respond(202, ['pending' => true, 'message' => 'Account created. The farm administrator must approve it before you can sign in.']);
        }
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => $userId, 'fullName' => $name, 'email' => $email, 'role' => $role];
        respond(201, ['user' => $_SESSION['user']]);
    }

    if ($method === 'POST' && $path === '/api/logout') {
        if (isset($_SESSION['user']['id'])) {
            audit($db, (int) $_SESSION['user']['id'], 'logout', 'user', (int) $_SESSION['user']['id']);
        }
        $_SESSION = [];
        $cookie = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $cookie['path'],
            'domain' => $cookie['domain'],
            'secure' => $cookie['secure'],
            'httponly' => $cookie['httponly'],
            'samesite' => $cookie['samesite'] ?? 'Lax',
        ]);
        session_destroy();
        respond(200, ['ok' => true]);
    }

    if ($method === 'GET' && $path === '/api/session') {
        respond(200, ['user' => requireUser()]);
    }

    if ($method === 'GET' && $path === '/api/dashboard') {
        $user = requireUser();
        requireRole($user, ['admin', 'worker', 'veterinarian']);
        $today = $db->query(
            "SELECT COALESCE(SUM(quantity_litres), 0) FROM milk_production WHERE production_date = CURRENT_DATE()"
        )->fetchColumn();
        $milkWeek = $db->query(
            'SELECT COALESCE(SUM(quantity_litres), 0) FROM milk_production
             WHERE production_date >= CURRENT_DATE() - INTERVAL 6 DAY'
        )->fetchColumn();
        $eggWeek = $db->query(
            'SELECT COALESCE(SUM(quantity_trays), 0) FROM egg_production
             WHERE production_date >= CURRENT_DATE() - INTERVAL 6 DAY'
        )->fetchColumn();
        $woolWeek = $db->query(
            'SELECT COALESCE(SUM(quantity_kg), 0) FROM wool_production
             WHERE production_date >= CURRENT_DATE() - INTERVAL 6 DAY'
        )->fetchColumn();
        $monthSales = $db->query(
            "SELECT COALESCE(SUM(quantity * unit_price), 0) FROM sales WHERE sold_at >= DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01')"
        )->fetchColumn();
        $activeAnimals = (int) $db->query(
            "SELECT COUNT(*) FROM animals WHERE status = 'active'"
        )->fetchColumn();
        $plannedTasks = (int) $db->query(
            "SELECT COUNT(*) FROM farm_activities WHERE status = 'planned' AND DATE(activity_at) <= CURRENT_DATE()"
        )->fetchColumn();
        $species = $db->query(
            "SELECT species, COUNT(*) AS total FROM animals WHERE status = 'active' GROUP BY species ORDER BY species"
        )->fetchAll();
        $stockAlerts = $db->query(
            'SELECT id, name, quantity_on_hand, reorder_level, unit FROM inventory_items
             WHERE quantity_on_hand <= reorder_level ORDER BY name'
        )->fetchAll();

        respond(200, [
            'activeAnimals' => $activeAnimals,
            'milkTodayLitres' => (float) $today,
            'milkWeekLitres' => (float) $milkWeek,
            'eggWeekTrays' => (float) $eggWeek,
            'woolWeekKg' => (float) $woolWeek,
            'salesThisMonth' => (float) $monthSales,
            'openTasks' => $plannedTasks,
            'herdBySpecies' => $species,
            'stockAlerts' => $stockAlerts,
        ]);
    }

    if ($path === '/api/animals' && $method === 'GET') {
        $user = requireUser();
        requireRole($user, ['admin', 'worker', 'veterinarian']);
        $animals = $db->query(
            'SELECT id, tag_number, species, breed, name, sex, date_of_birth, weight_kg, status, created_at
             FROM animals ORDER BY created_at DESC'
        )->fetchAll();
        respond(200, ['animals' => $animals]);
    }

    if ($path === '/api/animals' && $method === 'POST') {
        $user = requireUser();
        requireRole($user, ['admin', 'worker']);
        $speciesValues = ['cattle', 'sheep', 'goat', 'rabbit', 'chicken', 'other'];
        $species = (string) ($body['species'] ?? '');
        $tag = trim((string) ($body['tagNumber'] ?? ''));
        $weight = $body['weightKg'] ?? null;
        if (!in_array($species, $speciesValues, true) || ($weight !== null && (!is_numeric($weight) || $weight < 0))) {
            respond(422, ['error' => 'Choose a valid species and enter a non-negative weight.']);
        }

        $statement = $db->prepare(
            'INSERT INTO animals (tag_number, species, breed, name, sex, date_of_birth, weight_kg, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $tag === '' ? null : $tag,
            $species,
            trim((string) ($body['breed'] ?? '')) ?: null,
            trim((string) ($body['name'] ?? '')) ?: null,
            in_array($body['sex'] ?? '', ['female', 'male', 'unknown'], true) ? $body['sex'] : 'unknown',
            $body['dateOfBirth'] ?? null,
            $weight === null || $weight === '' ? null : $weight,
            trim((string) ($body['notes'] ?? '')) ?: null,
            $user['id'],
        ]);
        $animalId = (int) $db->lastInsertId();
        audit($db, $user['id'], 'create', 'animal', $animalId);
        respond(201, ['id' => $animalId]);
    }

    if (preg_match('#^/api/animals/(\d+)$#', $path, $matches) && $method === 'PATCH') {
        $user = requireUser();
        requireRole($user, ['admin']);
        $statuses = ['active', 'sold', 'deceased', 'transferred'];
        $status = (string) ($body['status'] ?? '');
        if (!in_array($status, $statuses, true)) {
            respond(422, ['error' => 'Choose a valid animal status.']);
        }
        $statement = $db->prepare('UPDATE animals SET status = ? WHERE id = ?');
        $statement->execute([$status, (int) $matches[1]]);
        if ($statement->rowCount() === 0) {
            respond(404, ['error' => 'Animal not found or status unchanged.']);
        }
        audit($db, $user['id'], 'update_status', 'animal', (int) $matches[1]);
        respond(200, ['ok' => true]);
    }

    if ($path === '/api/production/milk' && $method === 'GET') {
        $user = requireUser();
        requireRole($user, ['admin', 'worker']);
        $statement = $db->prepare(
            'SELECT production_date, SUM(quantity_litres) AS quantity_litres
             FROM milk_production WHERE production_date >= CURRENT_DATE() - INTERVAL 6 DAY
             GROUP BY production_date ORDER BY production_date'
        );
        $statement->execute();
        respond(200, ['production' => $statement->fetchAll()]);
    }

    if ($path === '/api/production/milk' && $method === 'POST') {
        $user = requireUser();
        requireRole($user, ['admin', 'worker']);
        $date = (string) ($body['productionDate'] ?? '');
        $session = (string) ($body['session'] ?? '');
        $quantity = $body['quantityLitres'] ?? null;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            || !in_array($session, ['morning', 'evening', 'other'], true)
            || !is_numeric($quantity) || $quantity < 0) {
            respond(422, ['error' => 'Enter a valid date, session, and non-negative milk quantity.']);
        }
        $statement = $db->prepare(
            'INSERT INTO milk_production (production_date, milking_session, quantity_litres, notes, recorded_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([$date, $session, $quantity, trim((string) ($body['notes'] ?? '')) ?: null, $user['id']]);
        $stock = $db->prepare(
            "UPDATE products SET available_quantity = available_quantity + ?
             WHERE product_type = 'produce' AND production_kind = 'milk' AND is_active = 1"
        );
        $stock->execute([$quantity]);
        audit($db, $user['id'], 'create', 'milk_production', (int) $db->lastInsertId());
        respond(201, ['ok' => true]);
    }

    if ($method === 'GET' && $path === '/api/production/summary') {
        $user = requireUser();
        requireRole($user, ['admin', 'worker']);
        $sources = [
            'milkLitres' => ['milk_production', 'quantity_litres'],
            'eggTrays' => ['egg_production', 'quantity_trays'],
            'woolKg' => ['wool_production', 'quantity_kg'],
        ];
        $todayDate = (string) $db->query('SELECT CURRENT_DATE()')->fetchColumn();
        $days = [];
        for ($offset = 6; $offset >= 0; $offset--) {
            $day = date('Y-m-d', strtotime("{$todayDate} -{$offset} day"));
            $days[$day] = ['date' => $day, 'milkLitres' => 0.0, 'eggTrays' => 0.0, 'woolKg' => 0.0];
        }
        $thisWeek = [];
        $previousWeek = [];
        foreach ($sources as $key => [$table, $column]) {
            $rows = $db->query(
                "SELECT production_date AS day, SUM({$column}) AS total FROM {$table}
                 WHERE production_date >= CURRENT_DATE() - INTERVAL 6 DAY GROUP BY production_date"
            )->fetchAll();
            $thisWeek[$key] = 0.0;
            foreach ($rows as $record) {
                $day = (string) $record['day'];
                if (isset($days[$day])) {
                    $days[$day][$key] = (float) $record['total'];
                    $thisWeek[$key] += (float) $record['total'];
                }
            }
            $previousWeek[$key] = (float) $db->query(
                "SELECT COALESCE(SUM({$column}), 0) FROM {$table}
                 WHERE production_date BETWEEN CURRENT_DATE() - INTERVAL 13 DAY AND CURRENT_DATE() - INTERVAL 7 DAY"
            )->fetchColumn();
        }
        respond(200, [
            'days' => array_values($days),
            'today' => $days[$todayDate] ?? ['date' => $todayDate, 'milkLitres' => 0.0, 'eggTrays' => 0.0, 'woolKg' => 0.0],
            'thisWeek' => $thisWeek,
            'previousWeek' => $previousWeek,
            'milkWeekLitres' => $thisWeek['milkLitres'],
            'eggWeekTrays' => $thisWeek['eggTrays'],
            'woolWeekKg' => $thisWeek['woolKg'],
        ]);
    }

    if ($method === 'GET' && $path === '/api/production/log') {
        $user = requireUser();
        requireRole($user, ['admin', 'worker']);
        $records = $db->query(
            "SELECT 'milk' AS product, m.production_date, m.quantity_litres AS quantity, 'litres' AS unit,
                    m.milking_session AS detail, u.full_name AS recorded_by, m.created_at
             FROM milk_production m LEFT JOIN users u ON u.id = m.recorded_by
             UNION ALL
             SELECT 'eggs', e.production_date, e.quantity_trays, 'trays', NULL, u.full_name, e.created_at
             FROM egg_production e LEFT JOIN users u ON u.id = e.recorded_by
             UNION ALL
             SELECT 'wool', w.production_date, w.quantity_kg, 'kg', NULL, u.full_name, w.created_at
             FROM wool_production w LEFT JOIN users u ON u.id = w.recorded_by
             ORDER BY created_at DESC LIMIT 60"
        )->fetchAll();
        respond(200, ['records' => $records]);
    }

    if ($path === '/api/production/wool' && $method === 'POST') {
        $user = requireUser();
        requireRole($user, ['admin', 'worker']);
        $date = (string) ($body['productionDate'] ?? '');
        $quantity = $body['quantityKg'] ?? null;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !is_numeric($quantity) || $quantity < 0) {
            respond(422, ['error' => 'Enter a valid date and non-negative wool quantity in kg.']);
        }
        $statement = $db->prepare(
            'INSERT INTO wool_production (production_date, quantity_kg, notes, recorded_by) VALUES (?, ?, ?, ?)'
        );
        $statement->execute([$date, $quantity, trim((string) ($body['notes'] ?? '')) ?: null, $user['id']]);
        $recordId = (int) $db->lastInsertId();
        $stock = $db->prepare(
            "UPDATE products SET available_quantity = available_quantity + ?
             WHERE product_type = 'produce' AND production_kind = 'wool' AND is_active = 1"
        );
        $stock->execute([$quantity]);
        audit($db, $user['id'], 'create', 'wool_production', $recordId);
        respond(201, ['ok' => true]);
    }

    if ($method === 'GET' && $path === '/api/production/forecast') {
        $user = requireUser();
        requireRole($user, ['admin', 'worker']);
        $observations = $db->query(
            'SELECT production_date AS date, SUM(quantity_litres) AS litres
             FROM milk_production
             WHERE production_date >= CURRENT_DATE() - INTERVAL 120 DAY
             GROUP BY production_date
             ORDER BY production_date ASC'
        )->fetchAll();
        $curl = curl_init('http://127.0.0.1:5000/forecast');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['observations' => $observations]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 5,
        ]);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);
        if ($response === false) {
            error_log($curlError);
            respond(503, ['error' => 'The ML forecast service is unavailable.']);
        }
        $forecast = json_decode($response, true);
        if (!is_array($forecast)) {
            respond(502, ['error' => 'The ML service returned an invalid response.']);
        }
        respond($status ?: 502, $forecast);
    }

    if ($path === '/api/health-records' && $method === 'GET') {
        $user = requireUser();
        requireRole($user, ['admin', 'veterinarian']);
        $records = $db->query(
            'SELECT h.id, h.animal_id, a.tag_number, a.name AS animal_name, h.record_type,
                    h.recorded_at, h.next_due_at, h.description, h.veterinarian
             FROM health_records h JOIN animals a ON a.id = h.animal_id
             ORDER BY h.recorded_at DESC LIMIT 200'
        )->fetchAll();
        respond(200, ['records' => $records]);
    }

    if ($path === '/api/health-records' && $method === 'POST') {
        $user = requireUser();
        requireRole($user, ['admin', 'veterinarian']);
        $types = ['vaccination', 'treatment', 'deworming', 'diagnosis', 'medication', 'incident', 'other'];
        $animalId = filter_var($body['animalId'] ?? null, FILTER_VALIDATE_INT);
        $recordType = (string) ($body['recordType'] ?? '');
        $recordedAt = str_replace('T', ' ', (string) ($body['recordedAt'] ?? ''));
        $description = trim((string) ($body['description'] ?? ''));
        if (!$animalId || !in_array($recordType, $types, true) || strtotime($recordedAt) === false || $description === '') {
            respond(422, ['error' => 'Choose an animal and record type, time, and description.']);
        }
        $nextDueAt = trim((string) ($body['nextDueAt'] ?? ''));
        $statement = $db->prepare(
            'INSERT INTO health_records (animal_id, record_type, recorded_at, next_due_at, description, veterinarian, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $animalId, $recordType, $recordedAt,
            $nextDueAt === '' ? null : str_replace('T', ' ', $nextDueAt),
            $description, trim((string) ($body['veterinarian'] ?? '')) ?: null, $user['id'],
        ]);
        audit($db, $user['id'], 'create', 'health_record', (int) $db->lastInsertId());
        respond(201, ['ok' => true]);
    }

    if ($path === '/api/production/eggs' && $method === 'GET') {
        $user = requireUser();
        requireRole($user, ['admin', 'worker']);
        $records = $db->query(
            'SELECT production_date, SUM(quantity_trays) AS quantity_trays
             FROM egg_production WHERE production_date >= CURRENT_DATE() - INTERVAL 30 DAY
             GROUP BY production_date ORDER BY production_date DESC'
        )->fetchAll();
        respond(200, ['production' => $records]);
    }

    if ($path === '/api/production/eggs' && $method === 'POST') {
        $user = requireUser();
        requireRole($user, ['admin', 'worker']);
        $date = (string) ($body['productionDate'] ?? '');
        $quantity = $body['quantityTrays'] ?? null;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !is_numeric($quantity) || $quantity < 0) {
            respond(422, ['error' => 'Enter a valid date and non-negative tray quantity.']);
        }
        $statement = $db->prepare(
            'INSERT INTO egg_production (production_date, quantity_trays, notes, recorded_by) VALUES (?, ?, ?, ?)'
        );
        $statement->execute([$date, $quantity, trim((string) ($body['notes'] ?? '')) ?: null, $user['id']]);
        $stock = $db->prepare(
            "UPDATE products SET available_quantity = available_quantity + ?
             WHERE product_type = 'produce' AND production_kind = 'eggs' AND is_active = 1"
        );
        $stock->execute([$quantity]);
        audit($db, $user['id'], 'create', 'egg_production', (int) $db->lastInsertId());
        respond(201, ['ok' => true]);
    }

    if ($path === '/api/inventory' && $method === 'GET') {
        $user = requireUser();
        requireRole($user, ['admin', 'worker']);
        $items = $db->query(
            'SELECT id, name, unit, quantity_on_hand, reorder_level FROM inventory_items ORDER BY name'
        )->fetchAll();
        respond(200, ['items' => $items]);
    }

    if ($path === '/api/inventory/items' && $method === 'POST') {
        $user = requireUser();
        requireRole($user, ['admin']);
        $name = trim((string) ($body['name'] ?? ''));
        $unit = trim((string) ($body['unit'] ?? ''));
        $quantity = $body['quantity'] ?? null;
        $reorderLevel = $body['reorderLevel'] ?? null;
        if ($name === '' || $unit === '' || !is_numeric($quantity) || $quantity < 0
            || !is_numeric($reorderLevel) || $reorderLevel < 0) {
            respond(422, ['error' => 'Enter an item name, unit, quantity, and reorder level.']);
        }
        $statement = $db->prepare(
            'INSERT INTO inventory_items (name, unit, quantity_on_hand, reorder_level) VALUES (?, ?, ?, ?)'
        );
        $statement->execute([$name, $unit, $quantity, $reorderLevel]);
        audit($db, $user['id'], 'create', 'inventory_item', (int) $db->lastInsertId());
        respond(201, ['ok' => true]);
    }

    if ($path === '/api/inventory/transactions' && $method === 'POST') {
        $user = requireUser();
        requireRole($user, ['admin', 'worker']);
        $itemId = filter_var($body['itemId'] ?? null, FILTER_VALIDATE_INT);
        $type = (string) ($body['type'] ?? '');
        $quantity = $body['quantity'] ?? null;
        if (!$itemId || !in_array($type, ['received', 'used', 'waste'], true) || !is_numeric($quantity) || $quantity <= 0) {
            respond(422, ['error' => 'Choose an item and transaction type, then enter a positive quantity.']);
        }

        $db->beginTransaction();
        $statement = $db->prepare('SELECT quantity_on_hand FROM inventory_items WHERE id = ? FOR UPDATE');
        $statement->execute([$itemId]);
        $current = $statement->fetchColumn();
        if ($current === false) {
            $db->rollBack();
            respond(404, ['error' => 'Inventory item not found.']);
        }
        $delta = $type === 'received' ? (float) $quantity : -(float) $quantity;
        if ((float) $current + $delta < 0) {
            $db->rollBack();
            respond(422, ['error' => 'This transaction would make the stock quantity negative.']);
        }
        $update = $db->prepare('UPDATE inventory_items SET quantity_on_hand = quantity_on_hand + ? WHERE id = ?');
        $update->execute([$delta, $itemId]);
        $insert = $db->prepare(
            'INSERT INTO inventory_transactions (inventory_item_id, transaction_type, quantity, transaction_at, notes, recorded_by)
             VALUES (?, ?, ?, CURRENT_TIMESTAMP, ?, ?)'
        );
        $insert->execute([$itemId, $type, $quantity, trim((string) ($body['notes'] ?? '')) ?: null, $user['id']]);
        $transactionId = (int) $db->lastInsertId();
        audit($db, $user['id'], 'create', 'inventory_transaction', $transactionId);
        $db->commit();
        respond(201, ['ok' => true]);
    }

    if ($method === 'GET' && $path === '/api/store/products') {
        $user = requireUser();
        requireRole($user, ['customer', 'buyer']);
        $typeFilter = $user['role'] === 'buyer'
            ? "products.product_type = 'livestock'"
            : "products.product_type <> 'livestock'";
        $products = $db->query(
            "SELECT products.id, products.name, products.product_type, products.production_kind,
                    products.species, products.unit, products.unit_price,
                    CASE WHEN products.product_type = 'livestock'
                         THEN (SELECT COUNT(*) FROM animals WHERE animals.species = products.species AND animals.status = 'active')
                         ELSE products.available_quantity END AS available_quantity
             FROM products WHERE products.is_active = 1 AND {$typeFilter}
             ORDER BY products.product_type, products.name"
        )->fetchAll();
        respond(200, ['products' => $products]);
    }

    if ($method === 'GET' && $path === '/api/store/livestock.csv') {
        $user = requireUser();
        requireRole($user, ['buyer']);
        $productId = filter_var($_GET['productId'] ?? null, FILTER_VALIDATE_INT);
        $quantity = filter_var($_GET['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        if (!$productId || !$quantity) {
            respond(422, ['error' => 'Choose a livestock listing and a whole number of animals.']);
        }
        $productStatement = $db->prepare(
            "SELECT name, species, unit_price FROM products
             WHERE id = ? AND is_active = 1 AND product_type = 'livestock'"
        );
        $productStatement->execute([$productId]);
        $product = $productStatement->fetch();
        if (!$product) {
            respond(404, ['error' => 'That livestock listing is not available.']);
        }
        // Same selection order used when the admin accepts the order.
        $animals = $db->prepare(
            "SELECT id, tag_number, species, breed, name, sex, date_of_birth, weight_kg
             FROM animals WHERE species = ? AND status = 'active' ORDER BY id LIMIT {$quantity}"
        );
        $animals->execute([$product['species']]);
        $rows = $animals->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) < $quantity) {
            respond(409, ['error' => 'Only ' . count($rows) . ' ' . $product['species'] . ' currently available.']);
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="flouri-farm-' . $product['species'] . '-selection.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Listing', 'Animal ID', 'Tag', 'Species', 'Breed', 'Name', 'Sex', 'Date of birth', 'Weight kg', 'Unit price KES']);
        foreach ($rows as $row) {
            fputcsv($output, [
                $product['name'], $row['id'], $row['tag_number'], $row['species'], $row['breed'], $row['name'],
                $row['sex'], $row['date_of_birth'], $row['weight_kg'], $product['unit_price'],
            ]);
        }
        fputcsv($output, []);
        fputcsv($output, ['Animals requested', $quantity, '', '', '', '', '', '', 'Estimated total KES', $quantity * (float) $product['unit_price']]);
        fclose($output);
        exit;
    }

    if ($path === '/api/products' && $method === 'GET') {
        $user = requireUser();
        requireRole($user, ['admin']);
        $products = $db->query(
            "SELECT products.id, products.name, products.product_type, products.production_kind,
                    products.species, products.unit, products.available_quantity, products.unit_price,
                    products.is_active,
                    CASE WHEN products.product_type = 'livestock'
                         THEN (SELECT COUNT(*) FROM animals WHERE animals.species = products.species AND animals.status = 'active')
                         ELSE products.available_quantity END AS current_availability
             FROM products ORDER BY products.product_type, products.name"
        )->fetchAll();
        respond(200, ['products' => $products]);
    }

    if ($path === '/api/products' && $method === 'POST') {
        $user = requireUser();
        requireRole($user, ['admin']);
        $name = trim((string) ($body['name'] ?? ''));
        $type = (string) ($body['productType'] ?? '');
        $species = $body['species'] ?? null;
        $productionKind = $body['productionKind'] ?? null;
        $unit = trim((string) ($body['unit'] ?? ''));
        $quantity = $body['availableQuantity'] ?? 0;
        $price = $body['unitPrice'] ?? null;
        $speciesValues = ['cattle', 'sheep', 'goat', 'rabbit', 'chicken', 'other'];
        if ($name === '' || !in_array($type, ['livestock', 'produce', 'other'], true)
            || $unit === '' || !is_numeric($price) || $price < 0
            || !is_numeric($quantity) || $quantity < 0
            || ($type === 'livestock' && !in_array($species, $speciesValues, true))
            || ($productionKind !== null && $productionKind !== '' && !in_array($productionKind, ['milk', 'eggs', 'wool', 'other'], true))) {
            respond(422, ['error' => 'Enter a name, valid type, unit, non-negative price and stock; livestock listings need a species.']);
        }
        if ($type === 'livestock' && (float) $quantity !== 0.0) {
            respond(422, ['error' => 'Livestock availability is calculated from active animal records.']);
        }
        $statement = $db->prepare(
            'INSERT INTO products (name, product_type, production_kind, species, unit, available_quantity, unit_price)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $name, $type, $productionKind ?: null, $type === 'livestock' ? $species : null,
            $type === 'livestock' ? 'animals' : $unit, $type === 'livestock' ? 0 : $quantity, $price,
        ]);
        audit($db, $user['id'], 'create', 'product', (int) $db->lastInsertId());
        respond(201, ['ok' => true]);
    }

    if (preg_match('#^/api/products/(\d+)$#', $path, $matches) && $method === 'PATCH') {
        $user = requireUser();
        requireRole($user, ['admin']);
        $quantity = $body['availableQuantity'] ?? null;
        $price = $body['unitPrice'] ?? null;
        $active = $body['isActive'] ?? null;
        if (($quantity !== null && (!is_numeric($quantity) || $quantity < 0))
            || ($price !== null && (!is_numeric($price) || $price < 0))
            || ($active !== null && !in_array($active, [true, false, 0, 1, '0', '1'], true))) {
            respond(422, ['error' => 'Enter non-negative stock and price and a valid active state.']);
        }
        $lookup = $db->prepare('SELECT product_type FROM products WHERE id = ?');
        $lookup->execute([(int) $matches[1]]);
        $productType = $lookup->fetchColumn();
        if ($productType === false) {
            respond(404, ['error' => 'Product not found.']);
        }
        if ($productType === 'livestock' && $quantity !== null) {
            respond(422, ['error' => 'Livestock availability is calculated from active animal records.']);
        }
        $statement = $db->prepare(
            'UPDATE products SET available_quantity = COALESCE(?, available_quantity),
             unit_price = COALESCE(?, unit_price), is_active = COALESCE(?, is_active) WHERE id = ?'
        );
        $statement->execute([$quantity, $price, $active === null ? null : (int) $active, (int) $matches[1]]);
        audit($db, $user['id'], 'update', 'product', (int) $matches[1]);
        respond(200, ['ok' => true]);
    }

    if ($path === '/api/sales' && $method === 'GET') {
        $user = requireUser();
        requireRole($user, ['admin']);
        $sales = $db->query(
            'SELECT id, description, quantity, unit, unit_price, sold_at,
                    quantity * unit_price AS total_amount
             FROM sales ORDER BY sold_at DESC LIMIT 200'
        )->fetchAll();
        respond(200, ['sales' => $sales]);
    }

    if ($path === '/api/sales' && $method === 'POST') {
        $user = requireUser();
        requireRole($user, ['admin']);
        $description = trim((string) ($body['description'] ?? ''));
        $unit = trim((string) ($body['unit'] ?? ''));
        $quantity = $body['quantity'] ?? null;
        $price = $body['unitPrice'] ?? null;
        $soldAt = str_replace('T', ' ', (string) ($body['soldAt'] ?? ''));
        if ($description === '' || $unit === '' || !is_numeric($quantity) || $quantity <= 0
            || !is_numeric($price) || $price < 0 || strtotime($soldAt) === false) {
            respond(422, ['error' => 'Enter a description, quantity, unit price, unit, and sale date.']);
        }
        $statement = $db->prepare(
            'INSERT INTO sales (description, quantity, unit, unit_price, sold_at, recorded_by) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([$description, $quantity, $unit, $price, $soldAt, $user['id']]);
        audit($db, $user['id'], 'create', 'sale', (int) $db->lastInsertId());
        respond(201, ['ok' => true]);
    }

    if ($method === 'GET' && $path === '/api/orders/export.csv') {
        $user = requireUser();
        if (in_array($user['role'], ['customer', 'buyer'], true)) {
            $statement = $db->prepare(
                'SELECT orders.id AS order_id, orders.ordered_at, orders.status, customers.name AS customer,
                        order_items.item_name, products.product_type, products.species,
                        order_items.quantity, order_items.unit, order_items.unit_price,
                        (SELECT GROUP_CONCAT(COALESCE(animals.tag_number, animals.name, animals.id) SEPARATOR "; ")
                         FROM order_animals JOIN animals ON animals.id = order_animals.animal_id
                         WHERE order_animals.order_id = orders.id) AS animal_identifiers
                 FROM orders JOIN customers ON customers.id = orders.customer_id
                 JOIN order_items ON order_items.order_id = orders.id
                 LEFT JOIN products ON products.id = order_items.product_id
                 WHERE customers.email = ? ORDER BY orders.ordered_at DESC, order_items.id'
            );
            $statement->execute([$user['email']]);
        } else {
            requireRole($user, ['admin']);
            $statement = $db->query(
                'SELECT orders.id AS order_id, orders.ordered_at, orders.status, customers.name AS customer,
                        order_items.item_name, products.product_type, products.species,
                        order_items.quantity, order_items.unit, order_items.unit_price,
                        (SELECT GROUP_CONCAT(COALESCE(animals.tag_number, animals.name, animals.id) SEPARATOR "; ")
                         FROM order_animals JOIN animals ON animals.id = order_animals.animal_id
                         WHERE order_animals.order_id = orders.id) AS animal_identifiers
                 FROM orders JOIN customers ON customers.id = orders.customer_id
                 JOIN order_items ON order_items.order_id = orders.id
                 LEFT JOIN products ON products.id = order_items.product_id
                 ORDER BY orders.ordered_at DESC, order_items.id'
            );
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="flouri-farm-orders.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Order', 'Date', 'Status', 'Customer', 'Item', 'Type', 'Species', 'Quantity', 'Unit', 'Unit price KES', 'Livestock identifiers']);
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    }

    if ($path === '/api/orders' && $method === 'GET') {
        $user = requireUser();
        if (in_array($user['role'], ['customer', 'buyer'], true)) {
            $statement = $db->prepare(
                'SELECT o.id, c.name AS customer, o.status, o.total_amount, o.ordered_at,
                    (SELECT GROUP_CONCAT(CONCAT(item_name, " x ", quantity, " ", unit) SEPARATOR "; ")
                     FROM order_items WHERE order_id = o.id) AS items
                 FROM orders o JOIN customers c ON c.id = o.customer_id
                 WHERE c.email = ? ORDER BY o.ordered_at DESC LIMIT 200'
            );
            $statement->execute([$user['email']]);
            $orders = $statement->fetchAll();
        } else {
            requireRole($user, ['admin']);
            $orders = $db->query(
                'SELECT o.id, c.name AS customer, o.status, o.total_amount, o.ordered_at,
                    (SELECT GROUP_CONCAT(CONCAT(item_name, " x ", quantity, " ", unit) SEPARATOR "; ")
                     FROM order_items WHERE order_id = o.id) AS items
                 FROM orders o JOIN customers c ON c.id = o.customer_id ORDER BY o.ordered_at DESC LIMIT 200'
            )->fetchAll();
        }
        respond(200, ['orders' => $orders]);
    }

    if ($path === '/api/orders' && $method === 'POST') {
        $user = requireUser();
        requireRole($user, ['admin', 'customer', 'buyer']);
        $shopper = in_array($user['role'], ['customer', 'buyer'], true);
        $customerName = $shopper
            ? $user['fullName']
            : trim((string) ($body['customerName'] ?? ''));
        $items = $body['items'] ?? null;
        if ($customerName === '' || !is_array($items) || count($items) < 1 || count($items) > 20) {
            respond(422, ['error' => 'Enter a customer and between 1 and 20 order items.']);
        }
        $validatedItems = [];
        foreach ($items as $item) {
            $quantity = $item['quantity'] ?? null;
            if (!is_numeric($quantity) || $quantity <= 0) {
                respond(422, ['error' => 'Each order item must have a positive quantity.']);
            }
            if ($shopper) {
                $productId = filter_var($item['productId'] ?? null, FILTER_VALIDATE_INT);
                if (!$productId) {
                    respond(422, ['error' => 'Choose an item from the farm catalog.']);
                }
                $productStatement = $db->prepare(
                    "SELECT products.name, products.product_type, products.species, products.unit,
                            products.unit_price, products.available_quantity,
                            CASE WHEN products.product_type = 'livestock'
                                 THEN (SELECT COUNT(*) FROM animals WHERE animals.species = products.species AND animals.status = 'active')
                                 ELSE products.available_quantity END AS current_availability
                     FROM products WHERE products.id = ? AND products.is_active = 1"
                );
                $productStatement->execute([$productId]);
                $product = $productStatement->fetch();
                if (!$product) {
                    respond(404, ['error' => 'That item is no longer listed.']);
                }
                if ($user['role'] === 'buyer' && $product['product_type'] !== 'livestock') {
                    respond(403, ['error' => 'Livestock buyer accounts can only purchase livestock.']);
                }
                if ($user['role'] === 'customer' && $product['product_type'] === 'livestock') {
                    respond(403, ['error' => 'Customer accounts can only purchase farm produce.']);
                }
                if ($product['product_type'] === 'livestock' && floor((float) $quantity) !== (float) $quantity) {
                    respond(422, ['error' => 'Livestock must be ordered as a whole number of animals.']);
                }
                if ((float) $quantity > (float) $product['current_availability']) {
                    respond(409, ['error' => "Only {$product['current_availability']} {$product['unit']} of {$product['name']} available. Reduce the quantity and try again."]);
                }
                $itemName = $product['name'];
                $unit = $product['unit'];
                $price = (float) $product['unit_price'];
            } else {
                $productId = null;
                $itemName = trim((string) ($item['itemName'] ?? ''));
                $unit = trim((string) ($item['unit'] ?? ''));
                $price = $item['unitPrice'] ?? null;
                if ($itemName === '' || $unit === '' || !is_numeric($price) || $price < 0) {
                    respond(422, ['error' => 'Each order item needs a name, unit, and non-negative price.']);
                }
            }
            $validatedItems[] = [$productId, $itemName, (float) $quantity, $unit, (float) $price];
        }

        $db->beginTransaction();
        if ($shopper) {
            $customerLookup = $db->prepare('SELECT id FROM customers WHERE email = ? LIMIT 1');
            $customerLookup->execute([$user['email']]);
            $customerId = $customerLookup->fetchColumn();
            if ($customerId === false) {
                $customer = $db->prepare('INSERT INTO customers (name, phone, email) VALUES (?, ?, ?)');
                $customer->execute([$customerName, null, $user['email']]);
                $customerId = (int) $db->lastInsertId();
            }
        } else {
            $customer = $db->prepare('INSERT INTO customers (name, phone, email) VALUES (?, ?, ?)');
            $customer->execute([$customerName, trim((string) ($body['customerPhone'] ?? '')) ?: null, null]);
            $customerId = (int) $db->lastInsertId();
        }
        $order = $db->prepare('INSERT INTO orders (customer_id, notes) VALUES (?, ?)');
        $order->execute([$customerId, trim((string) ($body['notes'] ?? '')) ?: null]);
        $orderId = (int) $db->lastInsertId();
        $insertItem = $db->prepare(
            'INSERT INTO order_items (order_id, product_id, item_name, quantity, unit, unit_price) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $total = 0.0;
        foreach ($validatedItems as [$productId, $itemName, $quantity, $unit, $price]) {
            $insertItem->execute([$orderId, $productId, $itemName, $quantity, $unit, $price]);
            $total += $quantity * $price;
        }
        $updateTotal = $db->prepare('UPDATE orders SET total_amount = ? WHERE id = ?');
        $updateTotal->execute([$total, $orderId]);
        audit($db, $user['id'], 'create', 'order', $orderId);
        $db->commit();
        respond(201, ['id' => $orderId, 'totalAmount' => $total]);
    }

    if (preg_match('#^/api/orders/(\d+)$#', $path, $matches) && $method === 'PATCH') {
        $user = requireUser();
        requireRole($user, ['admin']);
        $statuses = ['pending', 'accepted', 'fulfilled', 'cancelled'];
        $status = (string) ($body['status'] ?? '');
        $total = $body['totalAmount'] ?? null;
        if (!in_array($status, $statuses, true) || ($total !== null && (!is_numeric($total) || $total < 0))) {
            respond(422, ['error' => 'Choose a valid order status and non-negative total.']);
        }
        $orderId = (int) $matches[1];
        $db->beginTransaction();
        $orderLookup = $db->prepare('SELECT status FROM orders WHERE id = ? FOR UPDATE');
        $orderLookup->execute([$orderId]);
        $currentStatus = $orderLookup->fetchColumn();
        if ($currentStatus === false) {
            $db->rollBack();
            respond(404, ['error' => 'Order not found.']);
        }
        $allowedTransitions = [
            'pending' => ['pending', 'accepted', 'cancelled'],
            'accepted' => ['accepted', 'fulfilled', 'cancelled'],
            'fulfilled' => ['fulfilled'],
            'cancelled' => ['cancelled'],
        ];
        if (!in_array($status, $allowedTransitions[$currentStatus], true)) {
            $db->rollBack();
            respond(409, ['error' => 'That order status transition is not allowed.']);
        }

        if ($currentStatus === 'pending' && $status === 'accepted') {
            $itemsQuery = $db->prepare(
                'SELECT order_items.product_id, order_items.quantity, products.product_type, products.species
                 FROM order_items LEFT JOIN products ON products.id = order_items.product_id
                 WHERE order_items.order_id = ?'
            );
            $itemsQuery->execute([$orderId]);
            foreach ($itemsQuery->fetchAll() as $item) {
                if ($item['product_id'] === null) {
                    continue;
                }
                $productQuery = $db->prepare(
                    'SELECT id, product_type, species, available_quantity, is_active
                     FROM products WHERE id = ? FOR UPDATE'
                );
                $productQuery->execute([$item['product_id']]);
                $product = $productQuery->fetch();
                if (!$product || !(int) $product['is_active']) {
                    $db->rollBack();
                    respond(409, ['error' => 'A requested product is no longer available.']);
                }
                $quantity = (float) $item['quantity'];
                if ($product['product_type'] === 'livestock') {
                    $animalCount = (int) $quantity;
                    if ($animalCount < 1 || $animalCount !== $quantity) {
                        $db->rollBack();
                        respond(422, ['error' => 'Livestock requests must contain whole animals.']);
                    }
                    $animalsQuery = $db->query(
                        "SELECT id FROM animals WHERE species = " . $db->quote($product['species']) .
                        " AND status = 'active' ORDER BY id LIMIT {$animalCount} FOR UPDATE"
                    );
                    $animalIds = array_map('intval', $animalsQuery->fetchAll(PDO::FETCH_COLUMN));
                    if (count($animalIds) !== $animalCount) {
                        $db->rollBack();
                        respond(409, ['error' => 'Not enough animals remain in stock to accept this order.']);
                    }
                    $placeholders = implode(',', array_fill(0, count($animalIds), '?'));
                    $markSold = $db->prepare("UPDATE animals SET status = 'sold' WHERE id IN ({$placeholders})");
                    $markSold->execute($animalIds);
                    $linkAnimal = $db->prepare('INSERT INTO order_animals (order_id, animal_id) VALUES (?, ?)');
                    foreach ($animalIds as $animalId) {
                        $linkAnimal->execute([$orderId, $animalId]);
                    }
                } else {
                    $reduceStock = $db->prepare(
                        'UPDATE products SET available_quantity = available_quantity - ?
                         WHERE id = ? AND is_active = 1 AND available_quantity >= ?'
                    );
                    $reduceStock->execute([$quantity, $product['id'], $quantity]);
                    if ($reduceStock->rowCount() !== 1) {
                        $db->rollBack();
                        respond(409, ['error' => 'Not enough product stock remains to accept this order.']);
                    }
                }
            }
        } elseif ($currentStatus === 'accepted' && $status === 'cancelled') {
            $itemsQuery = $db->prepare(
                'SELECT order_items.product_id, order_items.quantity, products.product_type
                 FROM order_items LEFT JOIN products ON products.id = order_items.product_id
                 WHERE order_items.order_id = ?'
            );
            $itemsQuery->execute([$orderId]);
            foreach ($itemsQuery->fetchAll() as $item) {
                if ($item['product_id'] === null) {
                    continue;
                }
                if ($item['product_type'] === 'livestock') {
                    $animalQuery = $db->prepare('SELECT animal_id FROM order_animals WHERE order_id = ? FOR UPDATE');
                    $animalQuery->execute([$orderId]);
                    $animalIds = array_map('intval', $animalQuery->fetchAll(PDO::FETCH_COLUMN));
                    if ($animalIds) {
                        $placeholders = implode(',', array_fill(0, count($animalIds), '?'));
                        $restoreAnimals = $db->prepare("UPDATE animals SET status = 'active' WHERE id IN ({$placeholders}) AND status = 'sold'");
                        $restoreAnimals->execute($animalIds);
                    }
                    $deleteAnimals = $db->prepare('DELETE FROM order_animals WHERE order_id = ?');
                    $deleteAnimals->execute([$orderId]);
                } else {
                    $restoreStock = $db->prepare(
                        'UPDATE products SET available_quantity = available_quantity + ? WHERE id = ?'
                    );
                    $restoreStock->execute([$item['quantity'], $item['product_id']]);
                }
            }
        }

        $statement = $db->prepare(
            'UPDATE orders SET status = ?, total_amount = COALESCE(?, total_amount) WHERE id = ?'
        );
        $statement->execute([$status, $total, $orderId]);
        audit($db, $user['id'], 'update', 'order', $orderId);
        $db->commit();
        respond(200, ['ok' => true]);
    }

    if ($path === '/api/payments' && $method === 'GET') {
        $user = requireUser();
        requireRole($user, ['admin']);
        $payments = $db->query(
            'SELECT payments.id, payments.order_id, customers.name AS customer, payments.amount,
                    payments.method, payments.status, payments.transaction_reference, payments.paid_at
             FROM payments LEFT JOIN customers ON customers.id = payments.customer_id
             ORDER BY payments.created_at DESC LIMIT 200'
        )->fetchAll();
        respond(200, ['payments' => $payments]);
    }

    if ($path === '/api/payments' && $method === 'POST') {
        $user = requireUser();
        requireRole($user, ['admin']);
        $orderId = filter_var($body['orderId'] ?? null, FILTER_VALIDATE_INT);
        $methodName = (string) ($body['method'] ?? '');
        $reference = trim((string) ($body['transactionReference'] ?? ''));
        if (!$orderId || !in_array($methodName, ['cash', 'mpesa', 'bank', 'other'], true)) {
            respond(422, ['error' => 'Choose an order and payment method.']);
        }
        $orderStatement = $db->prepare(
            'SELECT orders.total_amount, orders.customer_id FROM orders WHERE orders.id = ?'
        );
        $orderStatement->execute([$orderId]);
        $order = $orderStatement->fetch();
        if (!$order) {
            respond(404, ['error' => 'Order not found.']);
        }
        if ((float) $order['total_amount'] <= 0) {
            respond(422, ['error' => 'Set a positive order total before recording payment.']);
        }
        $paymentStatus = $methodName === 'mpesa' ? 'pending' : 'confirmed';
        $paidAt = $paymentStatus === 'confirmed' ? date('Y-m-d H:i:s') : null;
        $statement = $db->prepare(
            'INSERT INTO payments (order_id, customer_id, amount, method, status, transaction_reference, paid_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $orderId, $order['customer_id'], $order['total_amount'], $methodName,
            $paymentStatus, $reference === '' ? null : $reference, $paidAt,
        ]);
        $paymentId = (int) $db->lastInsertId();
        audit($db, $user['id'], 'create', 'payment', $paymentId);
        respond(201, ['id' => $paymentId, 'status' => $paymentStatus]);
    }

    if ($path === '/api/activities' && $method === 'GET') {
        $user = requireUser();
        requireRole($user, ['admin', 'worker', 'veterinarian']);
        $activities = $db->query(
            'SELECT farm_activities.id, farm_activities.activity_type, farm_activities.description,
                    farm_activities.activity_at, farm_activities.status, animals.tag_number, animals.name AS animal_name
             FROM farm_activities LEFT JOIN animals ON animals.id = farm_activities.animal_id
             ORDER BY farm_activities.activity_at DESC LIMIT 200'
        )->fetchAll();
        respond(200, ['activities' => $activities]);
    }

    if ($path === '/api/activities' && $method === 'POST') {
        $user = requireUser();
        requireRole($user, ['admin', 'worker']);
        $type = trim((string) ($body['activityType'] ?? ''));
        $description = trim((string) ($body['description'] ?? ''));
        $activityAt = str_replace('T', ' ', (string) ($body['activityAt'] ?? ''));
        $status = (string) ($body['status'] ?? 'completed');
        $animalId = $body['animalId'] ?? null;
        if ($type === '' || $description === '' || strtotime($activityAt) === false
            || !in_array($status, ['planned', 'completed'], true)) {
            respond(422, ['error' => 'Enter an activity type, description, date, and valid status.']);
        }
        $animalId = $animalId === '' || $animalId === null ? null : filter_var($animalId, FILTER_VALIDATE_INT);
        if ($animalId === false) {
            respond(422, ['error' => 'Choose a valid animal.']);
        }
        $statement = $db->prepare(
            'INSERT INTO farm_activities (activity_type, description, activity_at, status, animal_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([$type, $description, $activityAt, $status, $animalId, $user['id']]);
        audit($db, $user['id'], 'create', 'farm_activity', (int) $db->lastInsertId());
        respond(201, ['ok' => true]);
    }

    if (preg_match('#^/api/activities/(\d+)$#', $path, $matches) && $method === 'PATCH') {
        $user = requireUser();
        requireRole($user, ['admin', 'worker']);
        if (($body['status'] ?? '') !== 'completed') {
            respond(422, ['error' => 'Activities can only be marked completed.']);
        }
        $statement = $db->prepare("UPDATE farm_activities SET status = 'completed' WHERE id = ? AND status = 'planned'");
        $statement->execute([(int) $matches[1]]);
        if ($statement->rowCount() === 0) {
            respond(404, ['error' => 'Planned activity not found.']);
        }
        audit($db, $user['id'], 'complete', 'farm_activity', (int) $matches[1]);
        respond(200, ['ok' => true]);
    }

    if ($path === '/api/expenses' && $method === 'GET') {
        $user = requireUser();
        requireRole($user, ['admin']);
        $expenses = $db->query(
            'SELECT id, category, description, amount, expense_date FROM expenses ORDER BY expense_date DESC LIMIT 200'
        )->fetchAll();
        respond(200, ['expenses' => $expenses]);
    }

    if ($path === '/api/expenses' && $method === 'POST') {
        $user = requireUser();
        requireRole($user, ['admin']);
        $category = trim((string) ($body['category'] ?? ''));
        $description = trim((string) ($body['description'] ?? ''));
        $amount = $body['amount'] ?? null;
        $expenseDate = (string) ($body['expenseDate'] ?? '');
        if ($category === '' || $description === '' || !is_numeric($amount) || $amount < 0
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expenseDate)) {
            respond(422, ['error' => 'Enter a category, description, non-negative amount, and valid date.']);
        }
        $statement = $db->prepare(
            'INSERT INTO expenses (category, description, amount, expense_date, recorded_by) VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([$category, $description, $amount, $expenseDate, $user['id']]);
        audit($db, $user['id'], 'create', 'expense', (int) $db->lastInsertId());
        respond(201, ['ok' => true]);
    }

    if ($method === 'GET' && $path === '/api/reports/finance') {
        $user = requireUser();
        requireRole($user, ['admin']);
        $sales = (float) $db->query(
            "SELECT COALESCE(SUM(quantity * unit_price), 0) FROM sales
             WHERE sold_at >= DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01')"
        )->fetchColumn();
        $expenses = (float) $db->query(
            "SELECT COALESCE(SUM(amount), 0) FROM expenses
             WHERE expense_date >= DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01')"
        )->fetchColumn();
        $payments = (float) $db->query(
            "SELECT COALESCE(SUM(amount), 0) FROM payments
             WHERE status = 'confirmed' AND paid_at >= DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01')"
        )->fetchColumn();
        respond(200, [
            'salesThisMonth' => $sales,
            'expensesThisMonth' => $expenses,
            'confirmedPaymentsThisMonth' => $payments,
            'estimatedNetFromRecordedSales' => $sales - $expenses,
        ]);
    }

    if ($path === '/api/feedback' && $method === 'GET') {
        $user = requireUser();
        if (in_array($user['role'], ['customer', 'buyer'], true)) {
            $statement = $db->prepare(
                'SELECT feedback.id, feedback.category, feedback.message, feedback.status, feedback.submitted_at
                 FROM feedback JOIN customers ON customers.id = feedback.customer_id
                 WHERE customers.email = ? ORDER BY feedback.submitted_at DESC LIMIT 100'
            );
            $statement->execute([$user['email']]);
            $records = $statement->fetchAll();
        } else {
            requireRole($user, ['admin']);
            $records = $db->query(
                'SELECT feedback.id, customers.name AS customer, feedback.category, feedback.message,
                        feedback.status, feedback.submitted_at
                 FROM feedback LEFT JOIN customers ON customers.id = feedback.customer_id
                 ORDER BY feedback.submitted_at DESC LIMIT 200'
            )->fetchAll();
        }
        respond(200, ['feedback' => $records]);
    }

    if ($path === '/api/feedback' && $method === 'POST') {
        $user = requireUser();
        requireRole($user, ['admin', 'customer', 'buyer']);
        $category = (string) ($body['category'] ?? '');
        $message = trim((string) ($body['message'] ?? ''));
        if (!in_array($category, ['complaint', 'feedback', 'suggestion', 'other'], true)
            || $message === '' || strlen($message) > 5000) {
            respond(422, ['error' => 'Choose a category and enter a message of at most 5,000 characters.']);
        }
        $customerId = null;
        if (in_array($user['role'], ['customer', 'buyer'], true)) {
            $lookup = $db->prepare('SELECT id FROM customers WHERE email = ? LIMIT 1');
            $lookup->execute([$user['email']]);
            $customerId = $lookup->fetchColumn();
            if ($customerId === false) {
                $insertCustomer = $db->prepare('INSERT INTO customers (name, email) VALUES (?, ?)');
                $insertCustomer->execute([$user['fullName'], $user['email']]);
                $customerId = (int) $db->lastInsertId();
            }
        }
        $statement = $db->prepare('INSERT INTO feedback (customer_id, category, message) VALUES (?, ?, ?)');
        $statement->execute([$customerId, $category, $message]);
        respond(201, ['ok' => true]);
    }

    if (preg_match('#^/api/feedback/(\d+)$#', $path, $matches) && $method === 'PATCH') {
        $user = requireUser();
        requireRole($user, ['admin']);
        if (($body['status'] ?? '') !== 'resolved') {
            respond(422, ['error' => 'Feedback can only be marked resolved.']);
        }
        $statement = $db->prepare("UPDATE feedback SET status = 'resolved' WHERE id = ? AND status <> 'resolved'");
        $statement->execute([(int) $matches[1]]);
        if ($statement->rowCount() === 0) {
            respond(404, ['error' => 'Open feedback not found.']);
        }
        audit($db, $user['id'], 'resolve', 'feedback', (int) $matches[1]);
        respond(200, ['ok' => true]);
    }

    if ($path === '/api/users' && $method === 'GET') {
        $user = requireUser();
        requireRole($user, ['admin']);
        $users = $db->query(
            'SELECT users.id, users.full_name, users.username, users.email, users.is_active, roles.name AS role
             FROM users JOIN roles ON roles.id = users.role_id ORDER BY users.full_name'
        )->fetchAll();
        respond(200, ['users' => $users]);
    }

    if ($path === '/api/account/username' && $method === 'POST') {
        $user = requireUser();
        $username = validUsername($body['username'] ?? '');
        if ($username === null) {
            respond(422, ['error' => 'Use 3–30 letters, numbers, _ or . for the username.']);
        }
        $statement = $db->prepare('UPDATE users SET username = ? WHERE id = ?');
        $statement->execute([$username, $user['id']]);
        audit($db, $user['id'], 'update_username', 'user', $user['id']);
        respond(200, ['ok' => true]);
    }

    if ($path === '/api/users' && $method === 'POST') {
        $user = requireUser();
        requireRole($user, ['admin']);
        $name = trim((string) ($body['fullName'] ?? ''));
        $username = validUsername($body['username'] ?? '');
        $email = filter_var(trim((string) ($body['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $password = (string) ($body['password'] ?? '');
        $role = (string) ($body['role'] ?? '');
        if ($name === '' || $username === null || $email === false || strlen($password) < 12
            || !in_array($role, ['worker', 'veterinarian', 'customer', 'buyer'], true)) {
            respond(422, ['error' => 'Enter a name, username (3–30 letters, numbers, _ or .), valid email, 12-character password, and supported role.']);
        }
        $statement = $db->prepare(
            'INSERT INTO users (role_id, full_name, username, email, password_hash)
             SELECT id, ?, ?, ?, ? FROM roles WHERE name = ?'
        );
        $statement->execute([$name, $username, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
        if ($statement->rowCount() !== 1) {
            respond(500, ['error' => "The '{$role}' role is missing. Run backend/database/migrate.php."]);
        }
        $newUserId = (int) $db->lastInsertId();
        audit($db, $user['id'], 'create', 'user', $newUserId);
        respond(201, ['id' => $newUserId]);
    }

    if (preg_match('#^/api/users/(\d+)$#', $path, $matches) && $method === 'PATCH') {
        $user = requireUser();
        requireRole($user, ['admin']);
        $targetId = (int) $matches[1];
        $active = $body['isActive'] ?? null;
        if (!is_bool($active)) {
            respond(422, ['error' => 'Choose whether the account is active.']);
        }
        if ($targetId === $user['id']) {
            respond(422, ['error' => 'You cannot deactivate your own admin account. Transfer admin rights first.']);
        }
        $statement = $db->prepare(
            "UPDATE users SET is_active = ? WHERE id = ?
             AND role_id <> (SELECT id FROM (SELECT id FROM roles WHERE name = 'admin') AS admin_role)"
        );
        $statement->execute([$active ? 1 : 0, $targetId]);
        if ($statement->rowCount() === 0) {
            respond(404, ['error' => 'Account not found or already in that state.']);
        }
        audit($db, $user['id'], $active ? 'approve' : 'deactivate', 'user', $targetId);
        respond(200, ['ok' => true]);
    }

    if ($path === '/api/admin/transfer' && $method === 'GET') {
        $user = requireUser();
        requireRole($user, ['admin']);
        respond(200, ['invite' => openAdminInvite($db) ?: null]);
    }

    if ($path === '/api/admin/transfer' && $method === 'POST') {
        $user = requireUser();
        requireRole($user, ['admin']);
        $email = filter_var(trim((string) ($body['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $newRole = (string) ($body['newRole'] ?? '');
        if ($email === false || strcasecmp($email, $user['email']) === 0
            || !in_array($newRole, ['worker', 'veterinarian', 'customer', 'buyer'], true)) {
            respond(422, ['error' => 'Enter the new admin\'s email (not your own) and the role you will take after the transfer.']);
        }
        verifyOwnPassword($db, $user['id'], (string) ($body['password'] ?? ''));

        $token = bin2hex(random_bytes(32));
        $db->beginTransaction();
        if ($existing = openAdminInvite($db, true)) {
            closeAdminInvite($db, $existing, 'cancelled');
        }
        $insert = $db->prepare(
            "INSERT INTO admin_transfer_invites (invited_by, email, token_hash, new_role_for_current, expires_at)
             VALUES (?, ?, ?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 72 HOUR))"
        );
        $insert->execute([$user['id'], $email, hash('sha256', $token), $newRole]);
        audit($db, $user['id'], 'invite_admin', 'admin_transfer_invite', (int) $db->lastInsertId());
        $db->commit();

        $inviteUrl = appUrl() . '/#admin_invite=' . rawurlencode($token);
        $emailed = sendMail(
            $email,
            'You have been invited to become the Flouri Farm LFMIS admin',
            "Hello,\n\n{$user['fullName']} has invited you to take over as administrator of Flouri Farm LFMIS.\n\n"
            . "Open this link to confirm. It expires in 72 hours:\n\n{$inviteUrl}\n\n"
            . "After you confirm, {$user['fullName']} will verify it is you before admin access is handed over.\n"
            . "If you were not expecting this, ignore this email.\n"
        );
        respond(201, ['inviteUrl' => $inviteUrl, 'emailed' => $emailed]);
    }

    if ($path === '/api/admin/transfer/cancel' && $method === 'POST') {
        $user = requireUser();
        requireRole($user, ['admin']);
        $db->beginTransaction();
        $invite = openAdminInvite($db, true);
        if ($invite) {
            closeAdminInvite($db, $invite, 'cancelled');
            audit($db, $user['id'], 'cancel_admin_invite', 'admin_transfer_invite', (int) $invite['id']);
        }
        $db->commit();
        respond(200, ['ok' => true]);
    }

    if ($path === '/api/admin/transfer/approve' && $method === 'POST') {
        $user = requireUser();
        requireRole($user, ['admin']);
        verifyOwnPassword($db, $user['id'], (string) ($body['password'] ?? ''));
        $db->beginTransaction();
        $invite = openAdminInvite($db, true);
        if (!$invite || $invite['status'] !== 'confirmed' || $invite['recipient_user_id'] === null) {
            $db->rollBack();
            respond(409, ['error' => 'There is no confirmed invitation to approve.']);
        }
        $setRole = $db->prepare('UPDATE users SET role_id = (SELECT id FROM roles WHERE name = ?) WHERE id = ?');
        $setRole->execute([$invite['new_role_for_current'], $user['id']]);
        $setRole->execute(['admin', $invite['recipient_user_id']]);
        $db->prepare('UPDATE users SET is_active = 1 WHERE id = ?')->execute([$invite['recipient_user_id']]);
        closeAdminInvite($db, $invite, 'approved');
        audit($db, $user['id'], 'transfer_admin', 'user', (int) $invite['recipient_user_id']);
        $db->commit();
        respond(200, ['user' => requireUser()]);
    }

    if ($path === '/api/admin-invite' && $method === 'GET') {
        $token = (string) ($_GET['token'] ?? '');
        $statement = $db->prepare(
            "SELECT i.email, i.status, u.full_name AS invited_by,
                    EXISTS(SELECT 1 FROM users WHERE users.email = i.email) AS has_account
             FROM admin_transfer_invites i JOIN users u ON u.id = i.invited_by
             WHERE i.token_hash = ? AND i.status = 'pending' AND i.expires_at > CURRENT_TIMESTAMP"
        );
        $statement->execute([preg_match('/^[a-f0-9]{64}$/', $token) ? hash('sha256', $token) : '']);
        $invite = $statement->fetch();
        if (!$invite) {
            respond(404, ['error' => 'This invitation is invalid, has expired, or has already been used.']);
        }
        respond(200, ['email' => $invite['email'], 'invitedBy' => $invite['invited_by'], 'hasAccount' => (bool) $invite['has_account']]);
    }

    if ($path === '/api/admin-invite/accept' && $method === 'POST') {
        $token = (string) ($body['token'] ?? '');
        $password = (string) ($body['password'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            respond(404, ['error' => 'This invitation is invalid, has expired, or has already been used.']);
        }
        $db->beginTransaction();
        $lookup = $db->prepare(
            "SELECT id, email FROM admin_transfer_invites
             WHERE token_hash = ? AND status = 'pending' AND expires_at > CURRENT_TIMESTAMP FOR UPDATE"
        );
        $lookup->execute([hash('sha256', $token)]);
        $invite = $lookup->fetch();
        if (!$invite) {
            $db->rollBack();
            respond(404, ['error' => 'This invitation is invalid, has expired, or has already been used.']);
        }
        $existing = $db->prepare('SELECT id, password_hash FROM users WHERE email = ?');
        $existing->execute([$invite['email']]);
        $account = $existing->fetch();
        $createdUser = 0;
        if ($account) {
            if (!password_verify($password, $account['password_hash'])) {
                $db->rollBack();
                respond(403, ['error' => 'That password does not match the existing account for this email.']);
            }
            $recipientId = (int) $account['id'];
        } else {
            $name = trim((string) ($body['fullName'] ?? ''));
            $username = validUsername($body['username'] ?? '');
            if ($name === '' || strlen($name) > 120 || $username === null || strlen($password) < 12) {
                $db->rollBack();
                respond(422, ['error' => 'Enter your full name, a username (3–30 letters, numbers, _ or .), and a password of at least 12 characters.']);
            }
            // Inactive placeholder role until the current admin approves the handover.
            $create = $db->prepare(
                "INSERT INTO users (role_id, full_name, username, email, password_hash, is_active)
                 SELECT id, ?, ?, ?, ?, 0 FROM roles WHERE name = 'customer'"
            );
            $create->execute([$name, $username, $invite['email'], password_hash($password, PASSWORD_DEFAULT)]);
            $recipientId = (int) $db->lastInsertId();
            $createdUser = 1;
        }
        $confirm = $db->prepare(
            "UPDATE admin_transfer_invites SET status = 'confirmed', recipient_user_id = ?, created_user = ?,
                    confirmed_at = CURRENT_TIMESTAMP WHERE id = ?"
        );
        $confirm->execute([$recipientId, $createdUser, $invite['id']]);
        audit($db, $recipientId, 'confirm_admin_invite', 'admin_transfer_invite', (int) $invite['id']);
        $db->commit();
        respond(200, ['message' => 'Confirmed. The current admin will now verify it is you and approve the handover. You can sign in as admin once it is approved.']);
    }

    respond(404, ['error' => 'Endpoint not found.']);
} catch (PDOException $error) {
    error_log($error->getMessage());
    if ($error->getCode() === '23000') {
        if (preg_match("/for key '(users\\.)?username'/", $error->getMessage())) {
            respond(409, ['error' => 'That username is already taken.']);
        }
        if (preg_match("/for key '(users\\.)?email'/", $error->getMessage())) {
            respond(409, ['error' => 'An account with that email already exists.']);
        }
        respond(409, ['error' => 'That record conflicts with an existing record.']);
    }
    respond(500, ['error' => 'Database operation failed. Check the backend configuration.']);
} catch (Throwable $error) {
    error_log($error->getMessage());
    respond(500, ['error' => 'An unexpected server error occurred.']);
}