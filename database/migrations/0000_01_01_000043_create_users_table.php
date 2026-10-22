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
        Schema::create('users', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigInteger('id')->unsigned()->autoIncrement();
            $table->bigInteger('role_id')->unsigned()->comment('Papel global padrão do usuário');
            $table->string('name', 150)->collation('utf8mb4_unicode_ci')->comment('Nome completo do usuário');
            $table->string('email', 191)->collation('utf8mb4_unicode_ci')->comment('E-mail para autenticação e notificações');
            $table->string('password_hash', 255)->collation('utf8mb4_unicode_ci')->comment('Hash da senha (Argon2id ou Bcrypt)');
            $table->enum('status', ['active', 'inactive', 'suspended'])->collation('utf8mb4_unicode_ci')->default('active')->comment('Estado da conta do usuário');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->string('remember_token', 100)->nullable()->collation('utf8mb4_unicode_ci');
            $table->unique(['email'], 'uq_users_email');
            $table->index(['role_id'], 'idx_users_role');
            $table->comment('Contas de usuários do SPACE.dev');
        });
        DB::statement('ALTER TABLE `users` ROW_FORMAT=DYNAMIC');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};
