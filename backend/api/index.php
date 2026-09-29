<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

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

function requireUser(): array
{
    if (!isset($_SESSION['user'])) {
        respond(401, ['error' => 'Please sign in to continue.']);
    }

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
        $email = filter_var(trim((string) ($body['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $password = (string) ($body['password'] ?? '');
        if ($name === '' || $email === false || strlen($password) < 12) {
            respond(422, ['error' => 'Enter a name, valid email, and password with at least 12 characters.']);
        }

        $roleId = $db->query("SELECT id FROM roles WHERE name = 'admin'")->fetchColumn();
        $statement = $db->prepare(
            'INSERT INTO users (role_id, full_name, email, password_hash) VALUES (?, ?, ?, ?)'
        );
        $statement->execute([$roleId, $name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        $userId = (int) $db->lastInsertId();
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => $userId, 'fullName' => $name, 'email' => $email, 'role' => 'admin'];
        audit($db, $userId, 'initial_setup', 'user', $userId);
        respond(201, ['user' => $_SESSION['user']]);
    }

    if ($method === 'POST' && $path === '/api/login') {
        $email = filter_var(trim((string) ($body['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $password = (string) ($body['password'] ?? '');
        if ($email === false || $password === '') {
            respond(422, ['error' => 'Enter your email and password.']);
        }

        $statement = $db->prepare(
            'SELECT users.id, users.full_name, users.email, users.password_hash, roles.name AS role
             FROM users JOIN roles ON roles.id = users.role_id
             WHERE users.email = ? AND users.is_active = 1 LIMIT 1'
        );
        $statement->execute([$email]);
        $user = $statement->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            respond(401, ['error' => 'Email or password is incorrect.']);
        }

        session_regenerate_id(true);
        unset($user['password_hash']);
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

                $appUrl = rtrim(getenv('LFMIS_APP_URL') ?: 'http://127.0.0.1:5173', '/');
                $appParts = parse_url($appUrl);
                if (!is_array($appParts) || !in_array($appParts['scheme'] ?? '', ['http', 'https'], true)
                    || empty($appParts['host'])) {
                    throw new RuntimeException('LFMIS_APP_URL must be an HTTP or HTTPS origin.');
                }
                $resetUrl = $appUrl . '/#reset_token=' . rawurlencode($token);
                $subject = 'Reset your Flouri Farm LFMIS password';
                $message = "Hello {$user['full_name']},\n\n"
                    . "Use this link to choose a new password. It expires in 30 minutes and can only be used once:\n\n"
                    . "{$resetUrl}\n\nIf you did not request this change, you can ignore this email.\n";
                $from = getenv('LFMIS_MAIL_FROM') ?: 'no-reply@localhost';
                if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
                    $from = 'no-reply@localhost';
                }
                $headers = "From: Flouri Farm LFMIS <{$from}>\r\nContent-Type: text/plain; charset=UTF-8";
                $devLogLink = filter_var(getenv('LFMIS_DEV_LOG_PASSWORD_RESET_LINK') ?: 'false', FILTER_VALIDATE_BOOLEAN);
                if ($devLogLink) {
                    error_log("Development password reset link for account {$user['id']}: {$resetUrl}");
                } elseif (!@mail($email, $subject, $message, $headers)) {
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

    if ($method === 'POST' && $path === '/api/logout') {
        $user = requireUser();
        audit($db, $user['id'], 'logout', 'user', $user['id']);
        $_SESSION = [];
        session_regenerate_id(true);
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
        $speciesValues = ['cattle', 'goat', 'rabbit', 'chicken', 'other'];
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
        audit($db, $user['id'], 'create', 'milk_production', (int) $db->lastInsertId());
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

    if ($path === '/api/orders' && $method === 'GET') {
        $user = requireUser();
        if ($user['role'] === 'customer') {
            $statement = $db->prepare(
                'SELECT o.id, c.name AS customer, o.status, o.total_amount, o.ordered_at
                 FROM orders o JOIN customers c ON c.id = o.customer_id
                 WHERE c.email = ? ORDER BY o.ordered_at DESC LIMIT 200'
            );
            $statement->execute([$user['email']]);
            $orders = $statement->fetchAll();
        } else {
            requireRole($user, ['admin']);
            $orders = $db->query(
                'SELECT o.id, c.name AS customer, o.status, o.total_amount, o.ordered_at
                 FROM orders o JOIN customers c ON c.id = o.customer_id ORDER BY o.ordered_at DESC LIMIT 200'
            )->fetchAll();
        }
        respond(200, ['orders' => $orders]);
    }

    if ($path === '/api/orders' && $method === 'POST') {
        $user = requireUser();
        requireRole($user, ['admin', 'customer']);
        $customerName = $user['role'] === 'customer'
            ? $user['fullName']
            : trim((string) ($body['customerName'] ?? ''));
        $items = $body['items'] ?? null;
        if ($customerName === '' || !is_array($items) || count($items) < 1 || count($items) > 20) {
            respond(422, ['error' => 'Enter a customer and between 1 and 20 order items.']);
        }
        $validatedItems = [];
        foreach ($items as $item) {
            $itemName = trim((string) ($item['itemName'] ?? ''));
            $unit = trim((string) ($item['unit'] ?? ''));
            $quantity = $item['quantity'] ?? null;
            $price = $user['role'] === 'customer' ? 0 : ($item['unitPrice'] ?? null);
            if ($itemName === '' || $unit === '' || !is_numeric($quantity) || $quantity <= 0
                || !is_numeric($price) || $price < 0) {
                respond(422, ['error' => 'Each order item needs a name, positive quantity, unit, and non-negative price.']);
            }
            $validatedItems[] = [$itemName, (float) $quantity, $unit, (float) $price];
        }

        $db->beginTransaction();
        if ($user['role'] === 'customer') {
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
            'INSERT INTO order_items (order_id, item_name, quantity, unit, unit_price) VALUES (?, ?, ?, ?, ?)'
        );
        $total = 0.0;
        foreach ($validatedItems as [$itemName, $quantity, $unit, $price]) {
            $insertItem->execute([$orderId, $itemName, $quantity, $unit, $price]);
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
        $statement = $db->prepare(
            'UPDATE orders SET status = ?, total_amount = COALESCE(?, total_amount) WHERE id = ?'
        );
        $statement->execute([$status, $total, (int) $matches[1]]);
        if ($statement->rowCount() === 0) {
            $exists = $db->prepare('SELECT 1 FROM orders WHERE id = ?');
            $exists->execute([(int) $matches[1]]);
            if (!$exists->fetchColumn()) {
                respond(404, ['error' => 'Order not found.']);
            }
        }
        audit($db, $user['id'], 'update', 'order', (int) $matches[1]);
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
        if ($user['role'] === 'customer') {
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
        requireRole($user, ['admin', 'customer']);
        $category = (string) ($body['category'] ?? '');
        $message = trim((string) ($body['message'] ?? ''));
        if (!in_array($category, ['complaint', 'feedback', 'suggestion', 'other'], true)
            || $message === '' || strlen($message) > 5000) {
            respond(422, ['error' => 'Choose a category and enter a message of at most 5,000 characters.']);
        }
        $customerId = null;
        if ($user['role'] === 'customer') {
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
            'SELECT users.id, users.full_name, users.email, users.is_active, roles.name AS role
             FROM users JOIN roles ON roles.id = users.role_id ORDER BY users.full_name'
        )->fetchAll();
        respond(200, ['users' => $users]);
    }

    if ($path === '/api/users' && $method === 'POST') {
        $user = requireUser();
        requireRole($user, ['admin']);
        $name = trim((string) ($body['fullName'] ?? ''));
        $email = filter_var(trim((string) ($body['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $password = (string) ($body['password'] ?? '');
        $role = (string) ($body['role'] ?? '');
        if ($name === '' || $email === false || strlen($password) < 12
            || !in_array($role, ['admin', 'worker', 'veterinarian', 'customer'], true)) {
            respond(422, ['error' => 'Enter a name, valid email, 12-character password, and supported staff role.']);
        }
        $statement = $db->prepare(
            'INSERT INTO users (role_id, full_name, email, password_hash)
             SELECT id, ?, ?, ? FROM roles WHERE name = ?'
        );
        $statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
        $newUserId = (int) $db->lastInsertId();
        audit($db, $user['id'], 'create', 'user', $newUserId);
        respond(201, ['id' => $newUserId]);
    }

    respond(404, ['error' => 'Endpoint not found.']);
} catch (PDOException $error) {
    error_log($error->getMessage());
    if ($error->getCode() === '23000') {
        respond(409, ['error' => 'That record conflicts with an existing record.']);
    }
    respond(500, ['error' => 'Database operation failed. Check the backend configuration.']);
} catch (Throwable $error) {
    error_log($error->getMessage());
    respond(500, ['error' => 'An unexpected server error occurred.']);
}