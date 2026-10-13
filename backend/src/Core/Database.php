<?php
declare(strict_types=1);

namespace Lfmis\Core;

use PDO;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection === null) {
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
        }
        return self::$connection;
    }
}
