<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DomainMigrationPlatform
{
    public static function assertSupported(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('SPACE.dev domain installation requires MySQL.');
        }
        $version = DB::selectOne('SELECT VERSION() AS version')->version;
        if (str_contains(strtolower($version), 'mariadb') || version_compare($version, '5.7.44', '<')) {
            throw new RuntimeException('Expected MySQL >= 5.7.44. MySQL 5.7 compatibility remains provisional until validated on that server.');
        }
    }
}
