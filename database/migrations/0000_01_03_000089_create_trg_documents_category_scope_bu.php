<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE TRIGGER `trg_documents_category_scope_bu` BEFORE UPDATE ON `documents` FOR EACH ROW BEGIN
    DECLARE v_category_project BIGINT UNSIGNED;
    IF NEW.`category_id` IS NOT NULL THEN
        SELECT `project_id` INTO v_category_project FROM `categories` WHERE `id` = NEW.`category_id`;
        IF v_category_project IS NOT NULL AND v_category_project <> NEW.`project_id` THEN
            SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'Categoria de outro projeto não pode ser associada ao documento\';
        END IF;
    END IF;
END');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};
