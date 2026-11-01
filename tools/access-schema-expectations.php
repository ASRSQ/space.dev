<?php

// Pure helper: extends the reviewed legacy inventory with the additive access migration.
return static function (array $inventory): array {
    $extension = json_decode(file_get_contents(dirname(__DIR__).'/database/schema/access-extension.json'), true, flags: JSON_THROW_ON_ERROR);
    if (str_contains($inventory['ddl']['projects'], '`organization_id`')) {
        throw new RuntimeException('The baseline already includes organization_id; review the extension before comparing.');
    }
    foreach ($extension['ddl'] as $table => $ddl) {
        if (isset($inventory['ddl'][$table])) {
            throw new RuntimeException('Duplicate access table in the baseline: '.$table);
        }
        $inventory['ddl'][$table] = $ddl;
    }
    $inventory['ddl']['projects'] = preg_replace('/\n\) ENGINE=/', ",\n  ".implode(",\n  ", $extension['project_definitions'])."\n) ENGINE=", $inventory['ddl']['projects'], 1);

    return $inventory;
};
