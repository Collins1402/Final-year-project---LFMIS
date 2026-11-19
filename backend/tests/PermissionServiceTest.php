<?php
declare(strict_types=1);

namespace Lfmis\Tests;

use Lfmis\Services\PermissionService;
use PDO;
use PHPUnit\Framework\TestCase;

final class PermissionServiceTest extends TestCase
{
    public function testAdminCanRevokeAndRestoreRolePermission(): void
    {
        $db = $this->database();
        $service = new PermissionService($db);

        $this->assertTrue($service->allows('worker', 'production.record'));
        $permissions = ['production.view' => true];
        $service->updateRole('worker', $permissions);

        $this->assertTrue($service->allows('worker', 'production.view'));
        $this->assertFalse($service->allows('worker', 'production.record'));
    }

    public function testAdministratorPermissionsCannotBeChanged(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Administrator permissions cannot be changed.');

        (new PermissionService($this->database()))->updateRole('admin', []);
    }

    public function testDatabaseBackupPermissionIsGrantedOnlyToAdministrator(): void
    {
        $db = $this->database();
        $db->exec("INSERT INTO permissions VALUES ('backup.create', 'Create database backup')");
        $db->exec("INSERT INTO role_permissions VALUES (1, 'backup.create', 1)");
        $service = new PermissionService($db);

        $this->assertTrue($service->allows('admin', 'backup.create'));
        $this->assertFalse($service->allows('worker', 'backup.create'));
    }

    private function database(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT)');
        $db->exec('CREATE TABLE permissions (code TEXT PRIMARY KEY, label TEXT)');
        $db->exec('CREATE TABLE role_permissions (role_id INTEGER, permission_code TEXT, is_allowed INTEGER, PRIMARY KEY (role_id, permission_code))');
        $db->exec("INSERT INTO roles VALUES (1, 'admin'), (2, 'worker')");
        $db->exec("INSERT INTO permissions VALUES ('production.view', 'View production'), ('production.record', 'Record production')");
        $db->exec("INSERT INTO role_permissions VALUES (2, 'production.view', 1), (2, 'production.record', 1)");
        return $db;
    }
}