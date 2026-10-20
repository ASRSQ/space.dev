<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DocumentationMySqlValidationTarget
{
    public const HOST = '127.0.0.1';
    public const PORT = 13317;
    public const DATABASE = 'spacedev_documentation_migration_validation';
    public const DATA_DIRECTORY = 'C:\\Users\\alex1\\AppData\\Local\\Temp\\spacedev-docs-validation-mysql84';

    /** @return array<string, mixed> */
    public static function assertConnected(): array
    {
        $connection = DB::connection('mysql');
        $config = $connection->getConfig();
        $actual = (array) $connection->selectOne(
            'SELECT VERSION() AS server_version, @@port AS server_port, @@bind_address AS bind_address, @@datadir AS data_directory, DATABASE() AS database_name'
        );

        $expectedDirectory = self::normalizePath(self::DATA_DIRECTORY);
        $actualDirectory = self::normalizePath((string) ($actual['data_directory'] ?? ''));

        if (($config['host'] ?? null) !== self::HOST
            || (int) ($config['port'] ?? 0) !== self::PORT
            || ($config['database'] ?? null) !== self::DATABASE
            || ($actual['database_name'] ?? null) !== self::DATABASE
            || (int) ($actual['server_port'] ?? 0) !== self::PORT
            || ($actual['bind_address'] ?? null) !== self::HOST
            || $actualDirectory !== $expectedDirectory
            || ! str_starts_with((string) ($actual['server_version'] ?? ''), '8.4.3')) {
            throw new RuntimeException('MySQL validation target check failed; refusing to continue.');
        }

        $actual['configured_host'] = $config['host'];
        $actual['configured_port'] = (int) $config['port'];
        $actual['configured_database'] = $config['database'];

        return $actual;
    }

    private static function normalizePath(string $path): string
    {
        $resolved = realpath($path) ?: $path;

        return rtrim(strtolower(str_replace('\\', '/', $resolved)), '/').'/';
    }
}
