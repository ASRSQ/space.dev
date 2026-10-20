<?php

// Offline transformation of the reviewed, schema-only inventory.
$root = dirname(__DIR__);
$inventory = json_decode(file_get_contents($root.'/docs/schema-inventory.json'), true, flags: JSON_THROW_ON_ERROR);
$schema = ['tables' => [], 'foreign_keys' => [], 'triggers' => []];
foreach ($inventory['ddl'] as $table => $ddl) {
    if (in_array($table, ['migrations', 'document_drafts', 'technical_context_drafts'])) {
        continue;
    }
    $lines = explode("\n", $ddl);
    $kept = [];
    foreach ($lines as $line) {
        if (preg_match('/^  CONSTRAINT .* FOREIGN KEY /', $line)) {
            $schema['foreign_keys'][] = "ALTER TABLE `$table` ADD ".rtrim(trim($line), ',');

            continue;
        }
        if (preg_match('/`section_key`|`uq_documents_project_section`|`uq_tech_ctx_project_section`|`uq_dcr_pending_document`|`uq_tccr_pending_context`/', $line)) {
            continue;
        }
        if ($table === 'tasks' && preg_match('/`start_date`|`due_date`|`completed_at`|`tasks_due_date_index`/', $line)) {
            continue;
        }
        $kept[] = $line;
    }
    $schema['tables'][$table] = preg_replace('/,\n\)/', "\n)", implode("\n", $kept));
}
foreach ($inventory['TRIGGERS'] as $trigger) {
    $schema['triggers'][] = 'CREATE TRIGGER `'.$trigger['TRIGGER_NAME'].'` '.$trigger['ACTION_TIMING'].' '.$trigger['EVENT_MANIPULATION'].' ON `'.$trigger['EVENT_OBJECT_TABLE'].'` FOR EACH ROW '.$trigger['ACTION_STATEMENT'];
}
@mkdir($root.'/database/schema', 0777, true);
file_put_contents($root.'/database/schema/space-baseline.json', json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
echo count($schema['tables']).' tables; '.count($schema['foreign_keys']).' FKs; '.count($schema['triggers'])." triggers\n";
