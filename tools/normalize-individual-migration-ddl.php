<?php

// Pure comparison helper: loading this file never boots Laravel or opens a database.
return static function (string $ddl): array {
    // Apply the existing display-width rule before matching the exact definition.
    $ddl = preg_replace('/\b(bigint|int|smallint)\(\d+\)/i', '$1', $ddl);
    // Only these two reviewed generated expressions may omit the introducer.
    // 'pending' is ASCII; the utf8mb4 status column (coercibility 2) determines
    // comparison collation, ahead of either literal (coercibility 4).
    // https://dev.mysql.com/doc/mysql-g11n-excerpt/5.7/en/charset-collation-coercibility.html
    foreach ([
        'document_change_requests' => ['pending_document_id', 'document_id'],
        'technical_context_change_requests' => ['pending_technical_context_id', 'technical_context_id'],
    ] as $table => [$column, $entity]) {
        if (! str_starts_with($ddl, "CREATE TABLE `$table` (\n")
            || ! preg_match("/^  `status` enum\('pending','approved','rejected','cancelled'\) (?:CHARACTER SET utf8mb4 )?COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending'(?: COMMENT '[^'\n]*')?,$/m", $ddl)
            || ! preg_match('/^\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci(?: |$)/m', $ddl)) {
            continue;
        }

        $introduced = "  `$column` bigint unsigned GENERATED ALWAYS AS ((case when (`status` = _utf8mb4'pending') then `$entity` else NULL end)) VIRTUAL,";
        $plain = str_replace("_utf8mb4'pending'", "'pending'", $introduced);
        // Match the whole definition, preserving explicit COLLATE, BINARY, type,
        // predicate, result, null branch and VIRTUAL/STORED differences.
        $ddl = preg_replace('/^'.preg_quote($introduced, '/').'$/m', $plain, $ddl);
    }

    $ddl = preg_replace('/ AUTO_INCREMENT=\d+/', '', $ddl);
    // Explicit DYNAMIC is equivalent to the reference's verified Dynamic default.
    $ddl = str_replace(' ROW_FORMAT=DYNAMIC', '', $ddl);
    // InnoDB treats NO ACTION and RESTRICT as the default immediate check.
    // SHOW CREATE can omit these explicit defaults on MySQL 5.7.
    $ddl = preg_replace('/ ON (?:DELETE|UPDATE) (?:NO ACTION|RESTRICT)\b/', '', $ddl);
    $ddl = str_replace(' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', ' COLLATE utf8mb4_unicode_ci', $ddl);
    $lines = explode("\n", $ddl);
    $lines = array_map(fn ($line) => rtrim(trim($line), ','), $lines);
    sort($lines);

    return $lines;
};
