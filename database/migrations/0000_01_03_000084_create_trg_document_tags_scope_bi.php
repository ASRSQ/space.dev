<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE TRIGGER `trg_document_tags_scope_bi` BEFORE INSERT ON `document_tags` FOR EACH ROW BEGIN
    DECLARE v_document_project BIGINT UNSIGNED;
    DECLARE v_tag_project BIGINT UNSIGNED;
    SELECT `project_id` INTO v_document_project FROM `documents` WHERE `id` = NEW.`document_id`;
    SELECT `project_id` INTO v_tag_project FROM `tags` WHERE `id` = NEW.`tag_id`;
    IF v_tag_project IS NOT NULL AND v_tag_project <> v_document_project THEN
        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'Tag de outro projeto não pode ser associada ao documento\';
    END IF;
END');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};
