<?php
declare(strict_types=1);

namespace Lfmis\Services;

use PDO;

final class PermissionService
{
    private const PERMISSIONS = [
        'animals.view' => 'View livestock',
        'animals.create' => 'Register livestock',
        'animals.status' => 'Update livestock status',
        'health.view' => 'View animal health',
        'health.record' => 'Record animal care',
        'users.manage' => 'Manage user accounts',
        'account.security' => 'Manage personal account security',
        'roles.manage' => 'Manage role permissions',
        'events.manage' => 'Manage farm events',
        'activities.view' => 'View activities',
        'activities.create' => 'Create activities',
        'activities.complete' => 'Complete activities',
        'production.view' => 'View production',
        'production.record' => 'Record production',
        'inventory.view' => 'View inventory',
        'inventory.manage' => 'Manage inventory items',
        'inventory.transact' => 'Record inventory movements',
        'products.view' => 'View listings',
        'products.manage' => 'Manage listings',
        'orders.view' => 'View orders',
        'orders.create' => 'Create orders',
        'orders.manage' => 'Manage orders',
        'payments.view' => 'View payments',
        'finance.view' => 'View sales and expenses',
        'finance.manage' => 'Record sales, expenses and payments',
        'reports.finance' => 'View financial reports',
        'reports.operations' => 'View operational reports',
        'reports.forecast' => 'View production forecasts',
        'assistant.use' => 'Use the farm assistant',
        'demo.manage' => 'Load demonstration data',
        'notifications.view' => 'View notifications',
        'feedback.view' => 'View feedback',
        'feedback.create' => 'Submit feedback',
        'feedback.resolve' => 'Resolve feedback',
        'scenarios.run' => 'Run 30-day yield scenarios',
        'audit.view' => 'View audit log',
        'data.export' => 'Export farm data',
        'backup.create' => 'Create database backup',
        'trends.view' => 'View farm trends and market intelligence',
        'trends.manage' => 'Refresh farm trends data',
    ];

    public function __construct(private PDO $db)
    {
    }

    public function allows(string $role, string $permission): bool
    {
        $statement = $this->db->prepare(
            'SELECT rp.is_allowed FROM role_permissions rp JOIN roles r ON r.id = rp.role_id
             WHERE r.name = ? AND rp.permission_code = ?'
        );
        $statement->execute([$role, $permission]);
        return (int) $statement->fetchColumn() === 1;
    }

    public function canViewAnalytics(string $role, string $scope): bool
    {
        return match ($scope) {
            'production' => in_array($role, ['admin', 'worker'], true),
            'health' => in_array($role, ['admin', 'worker', 'veterinarian'], true),
            'finance' => $role === 'admin',
            default => false,
        };
    }

    public function matrix(): array
    {
        $roles = $this->db->query('SELECT id, name FROM roles ORDER BY id')->fetchAll();
        $grants = $this->db->query('SELECT role_id, permission_code, is_allowed FROM role_permissions')->fetchAll();
        $grantMap = [];
        foreach ($grants as $grant) {
            $grantMap[(int) $grant['role_id']][$grant['permission_code']] = (bool) $grant['is_allowed'];
        }
        $matrix = [];
        foreach ($roles as $role) {
            $matrix[$role['name']] = [];
            foreach (self::PERMISSIONS as $code => $label) {
                $matrix[$role['name']][$code] = [
                    'label' => $label,
                    'allowed' => $grantMap[(int) $role['id']][$code] ?? false,
                ];
            }
        }
        return $matrix;
    }

    public function updateRole(string $role, array $permissions): array
    {
        if ($role === 'admin') {
            throw new \InvalidArgumentException('Administrator permissions cannot be changed.');
        }
        $roleId = $this->db->prepare('SELECT id FROM roles WHERE name = ?');
        $roleId->execute([$role]);
        $id = $roleId->fetchColumn();
        if ($id === false) {
            throw new \InvalidArgumentException('Unsupported role.');
        }
        foreach ($permissions as $code => $allowed) {
            if (!isset(self::PERMISSIONS[$code]) || !is_bool($allowed)) {
                throw new \InvalidArgumentException('Choose valid permissions and boolean access values.');
            }
        }
        $this->db->beginTransaction();
        try {
            $delete = $this->db->prepare('DELETE FROM role_permissions WHERE role_id = ?');
            $delete->execute([(int) $id]);
            $insert = $this->db->prepare('INSERT INTO role_permissions (role_id, permission_code, is_allowed) VALUES (?, ?, ?)');
            foreach (self::PERMISSIONS as $code => $_label) {
                $insert->execute([(int) $id, $code, !empty($permissions[$code]) ? 1 : 0]);
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
        return $this->matrix();
    }
}
