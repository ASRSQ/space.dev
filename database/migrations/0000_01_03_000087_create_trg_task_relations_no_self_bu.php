<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE TRIGGER `trg_task_relations_no_self_bu` BEFORE UPDATE ON `task_relations` FOR EACH ROW BEGIN
    IF NEW.`source_task_id` = NEW.`target_task_id` THEN
        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'Uma tarefa não pode se relacionar consigo mesma\';
    END IF;
END');
    }

    public function down(): void
    {
        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');
    }
};
