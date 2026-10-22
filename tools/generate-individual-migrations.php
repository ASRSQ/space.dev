<?php

// Development-only generator: reads the live source; never executes source DDL.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection('mysql');
if ($db->getDatabaseName() !== 'spacedev_test') {
    throw new RuntimeException('Expected read-only spacedev_test source.');
}
$baseline = json_decode(file_get_contents(database_path('schema/space-baseline.json')), true, flags: JSON_THROW_ON_ERROR);
$inventory = json_decode(file_get_contents(base_path('docs/schema-inventory.json')), true, flags: JSON_THROW_ON_ERROR);
foreach ($inventory['ddl'] as $name => $expected) {
    $row = (array) $db->selectOne("SHOW CREATE TABLE `$name`");
    if (preg_replace('/ AUTO_INCREMENT=\d+/', '', $row['Create Table']) !== $expected) {
        throw new RuntimeException("Source changed: $name. Repeat diagnosis.");
    }
}
$quote = static fn ($value) => is_array($value) ? '['.implode(', ', array_map(static fn ($v) => var_export($v, true), $value)).']' : var_export($value, true);
$header = "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\DB;\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n";
$footer = "    }\n\n    public function down(): void\n    {\n        throw new RuntimeException('Domain rollback is blocked to protect data. Recreate only an explicitly disposable database.');\n    }\n};\n";
$files = [];
$emit = static function ($name, $body) use ($header, $footer, &$files) {
    $path = database_path('migrations/'.$name.'.php');
    if (file_exists($path) && ! in_array('--replace-generated', $_SERVER['argv'], true)) {
        throw new RuntimeException("Refusing to overwrite $path");
    }
    $files[$path] = $header.$body.$footer;
};
$n = 0;
foreach ($baseline['tables'] as $name => $ddl) {
    $body = "        \\App\\Support\\DomainMigrationPlatform::assertSupported();\n        Schema::create('$name', function (Blueprint \$table): void {\n            \$table->engine = 'InnoDB';\n            \$table->charset = 'utf8mb4';\n            \$table->collation = 'utf8mb4_unicode_ci';\n";
    $columns = [];
    foreach ($db->select('SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', ['spacedev_test', $name]) as $c) {
        // Incremental columns remain owned by their original migrations.
        if (! preg_match('/^  `'.preg_quote($c->COLUMN_NAME, '/').'` /m', $ddl)) {
            continue;
        }
        $col = $quote($c->COLUMN_NAME);
        $type = $c->DATA_TYPE;
        $method = ['bigint' => 'bigInteger', 'int' => 'integer', 'smallint' => 'smallInteger', 'tinyint' => 'tinyInteger', 'varchar' => 'string', 'char' => 'char', 'text' => 'text', 'mediumtext' => 'mediumText', 'datetime' => 'dateTime', 'timestamp' => 'timestamp', 'date' => 'date', 'json' => 'json'][$type] ?? null;
        if ($type === 'enum') {
            preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $c->COLUMN_TYPE, $values);
            $expr = '\$table->enum('.$col.', '.$quote(array_map('stripcslashes', $values[1])).')';
            $expr = str_replace('\\$table', '$table', $expr);
        } elseif ($c->COLUMN_TYPE === 'tinyint(1)') {
            $expr = '$table->boolean('.$col.')';
        } elseif ($method !== null) {
            $expr = '$table->'.$method.'('.$col.(in_array($type, ['varchar', 'char']) ? ', '.$c->CHARACTER_MAXIMUM_LENGTH : '').')';
        } else {
            throw new RuntimeException("Unsupported type $name.$c->COLUMN_NAME: $type");
        }
        if (str_contains($c->COLUMN_TYPE, 'unsigned')) {
            $expr .= '->unsigned()';
        }
        if (str_contains($c->EXTRA, 'auto_increment')) {
            $expr .= '->autoIncrement()';
        }
        if ($c->IS_NULLABLE === 'YES') {
            $expr .= '->nullable()';
        }
        if ($c->COLLATION_NAME !== null) {
            $expr .= '->collation('.$quote($c->COLLATION_NAME).')';
        }
        if ($c->GENERATION_EXPRESSION !== '') {
            preg_match('/GENERATED ALWAYS AS \((.*)\) VIRTUAL/', $ddl, $generated);
            $expr .= '->virtualAs('.$quote($generated[1]).')';
        } elseif ($c->COLUMN_DEFAULT !== null) {
            $expr .= strtoupper($c->COLUMN_DEFAULT) === 'CURRENT_TIMESTAMP' ? '->useCurrent()' : '->default('.$quote($c->COLUMN_DEFAULT).')';
        }
        if (str_contains(strtolower($c->EXTRA), 'on update current_timestamp')) {
            $expr .= '->useCurrentOnUpdate()';
        }
        if ($c->COLUMN_COMMENT !== '') {
            $expr .= '->comment('.$quote($c->COLUMN_COMMENT).')';
        }
        $body .= '            '.$expr.";\n";
        $columns[$c->COLUMN_NAME] = str_contains($c->EXTRA, 'auto_increment');
    }
    $commentedIndexes = [];
    foreach (explode("\n", $ddl) as $line) {
        if (! preg_match('/^  (PRIMARY KEY|UNIQUE KEY `([^`]+)`|KEY `([^`]+)`) \((.*?)\)(?: COMMENT \'(.*)\')?,?$/', $line, $m)) {
            continue;
        }
        preg_match_all('/`([^`]+)`/', $m[4], $parts);
        $keys = $parts[1];
        if ($m[1] === 'PRIMARY KEY' && count($keys) === 1 && $columns[$keys[0]]) {
            continue;
        }
        $method = $m[1] === 'PRIMARY KEY' ? 'primary' : (str_starts_with($m[1], 'UNIQUE') ? 'unique' : 'index');
        $indexName = $m[2] ?: ($m[3] ?: 'PRIMARY');
        if (! empty($m[5])) {
            // MySqlGrammar ignores Blueprint index comments; preserve exact DDL.
            $commentedIndexes[] = 'ALTER TABLE `'.$name.'` ADD '.rtrim(trim($line), ',');

            continue;
        }
        $body .= '            $table->'.$method.'('.$quote($keys).', '.$quote($indexName).')'.(! empty($m[5]) ? '->comment('.$quote($m[5]).')' : '').";\n";
    }
    if (preg_match("/\) ENGINE=.* COMMENT='(.*)'$/s", $ddl, $comment)) {
        $body .= '            $table->comment('.$quote($comment[1]).");\n";
    }
    $body .= "        });\n        DB::statement('ALTER TABLE `$name` ROW_FORMAT=DYNAMIC');\n";
    foreach ($commentedIndexes as $sql) {
        $body .= '        DB::statement('.$quote($sql).");\n";
    }
    $emit('0000_01_01_'.sprintf('%06d', ++$n).'_create_'.$name.'_table', $body);
}
$groups = [];
foreach ($baseline['foreign_keys'] as $sql) {
    if (! preg_match('/ALTER TABLE `([^`]+)` ADD CONSTRAINT `([^`]+)` FOREIGN KEY \(([^)]+)\) REFERENCES `([^`]+)` \(([^)]+)\) ON DELETE (RESTRICT|CASCADE|SET NULL|NO ACTION) ON UPDATE (RESTRICT|CASCADE|SET NULL|NO ACTION)/', $sql, $m)) {
        throw new RuntimeException('Unparsed FK: '.$sql);
    }
    $parse = static function ($s) {
        preg_match_all('/`([^`]+)`/', $s, $p);

        return $p[1];
    };
    $groups[$m[1]][] = '            $table->foreign('.$quote($parse($m[3])).', '.$quote($m[2]).')->references('.$quote($parse($m[5])).')->on('.$quote($m[4]).')->onDelete('.$quote(strtolower($m[6])).')->onUpdate('.$quote(strtolower($m[7])).');';
}
foreach ($groups as $table => $lines) {
    $emit('0000_01_02_'.sprintf('%06d', ++$n).'_add_'.$table.'_foreign_keys', "        Schema::table('$table', function (Blueprint \$table): void {\n".implode("\n", $lines)."\n        });\n");
}
foreach ($baseline['triggers'] as $sql) {
    preg_match('/CREATE TRIGGER `([^`]+)`/', $sql, $m);
    $emit('0000_01_03_'.sprintf('%06d', ++$n).'_create_'.$m[1], '        DB::unprepared('.$quote($sql).");\n");
}
foreach ($files as $path => $contents) {
    file_put_contents($path, $contents);
}
echo count($baseline['tables']).' table migrations; '.count($groups).' FK migrations; '.count($baseline['triggers'])." trigger migrations.\n";
