<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class AccessManagementMigrationTest extends TestCase
{
    public function test_additive_migration_preserves_legacy_rows_and_refuses_destructive_rollback(): void
    {
        $this->assertSame('sqlite', DB::getDriverName());
        Schema::create('users', fn (Blueprint $t) => $t->id());
        Schema::create('projects', function (Blueprint $t) {
            $t->id();
            $t->string('name');
        });
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('assigned_user_id');
            $t->text('description');
        });
        DB::table('users')->insert(['id' => 4]);
        DB::table('projects')->insert(['id' => 3, 'name' => 'Projeto legado']);
        DB::table('tasks')->insert(['id' => 3, 'assigned_user_id' => 4, 'description' => "# Antiga\r\n- [x] Preservar"]);
        $before = (array) DB::table('tasks')->find(3);
        $migration = require database_path('migrations/2026_10_23_000001_add_organization_access_management.php');
        $migration->up();
        $this->assertSame($before, (array) DB::table('tasks')->find(3));
        $this->assertNull(DB::table('projects')->find(3)->organization_id);
        $this->assertSame(0, DB::table('organizations')->count());
        try {
            $migration->down();
            $this->fail('Destructive rollback allowed');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Rollback blocked', $e->getMessage());
        }
        $this->assertSame($before, (array) DB::table('tasks')->find(3));
    }
}
