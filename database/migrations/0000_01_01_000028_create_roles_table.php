<?php

use App\Support\DomainMigrationPlatform;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DomainMigrationPlatform::assertSupported();
        Schema::create('roles', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->string('name', 50)->collation('utf8mb4_unicode_ci')->comment('Nome legível do papel (ex: Administrator, Programmer)');
            $table->string('slug', 50)->collation('utf8mb4_unicode_ci')->comment('Identificador de sistema do papel');
            $table->string('description', 255)->nullable()->collation('utf8mb4_unicode_ci')->comment('Descrição das responsabilidades do papel');
            $table->dateTime('created_at')->useCurrent();
            $table->unique(['name'], 'uq_roles_name');
            $table->unique(['slug'], 'uq_roles_slug');
            $table->comment('Papéis predefinidos da plataforma SPACE.dev');
        });
        DB::statement('ALTER TABLE `roles` ROW_FORMAT=DYNAMIC');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};
