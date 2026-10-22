<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prompt_variables', function (Blueprint $table): void {
            $table->foreign(['prompt_template_id'], 'fk_pv_var_template')->references(['id'])->on('prompt_templates')->onDelete('cascade')->onUpdate('cascade');
            $table->foreign(['prompt_version_id'], 'fk_pv_var_version')->references(['id'])->on('prompt_versions')->onDelete('cascade')->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};
