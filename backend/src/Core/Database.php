<?php
/**
 * File purpose: implements Database for the farm management system.
 * Called by the API router or other services that need this shared operation.
 * Depends on the declared PHP classes, database connection, and configuration used below.
 */
declare(strict_types=1);

namespace Lfmis\Core;

use PDO;

final class Database
{
    private static ?PDO $connection = null;

    /**
 * Performs the connection operation for this service.
 * @return PDO Result of this operation.
 */
    public static function connection(): PDO
    {
        if (self::$connection === null) {
            // Credentials stay in environment variables; the application opens one MySQL connection per request.
            $password = Env::get('LFMIS_DB_PASSWORD_EMPTY') === '1'
                ? ''
                : Env::required('LFMIS_DB_PASSWORD');
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                Env::get('LFMIS_DB_HOST', '127.0.0.1'),
                Env::get('LFMIS_DB_PORT', '4321'),
                Env::required('LFMIS_DB_NAME')
            );
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ];
            self::$connection = new PDO($dsn, Env::required('LFMIS_DB_USER'), $password, $options);
            $timezone = new \DateTimeZone(Env::get('LFMIS_TIMEZONE', 'Africa/Nairobi'));
            $offsetSeconds = $timezone->getOffset(new \DateTimeImmutable('now', $timezone));
            $absoluteOffset = abs($offsetSeconds);
            $offset = sprintf('%s%02d:%02d', $offsetSeconds < 0 ? '-' : '+', intdiv($absoluteOffset, 3600), intdiv($absoluteOffset % 3600, 60));
            self::$connection->exec("SET time_zone = '{$offset}'");
        }
        return self::$connection;
    }
}
