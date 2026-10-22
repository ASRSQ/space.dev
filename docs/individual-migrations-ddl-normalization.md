# Generated-column literal comparison

The HostGator MySQL 5.7.44-48 run completed 94 migrations, creating 53 tables
and 6 triggers with zero application rows (reported by the operator). Its
`SHOW CREATE TABLE` uses `'pending'` where the reviewed reference uses
`_utf8mb4'pending'` in the two pending-request generated columns.

These literals do not have identical charset/collation metadata in every
connection. Without an introducer, a literal uses the connection charset and
collation; `_utf8mb4` specifies utf8mb4 and, without explicit COLLATE, its default
collation. However, in these specific comparisons both literals represent the
same ASCII value. The `status` column has charset utf8mb4 and collation
utf8mb4_unicode_ci. Its coercibility is 2, ahead of either literal's 4, so the
column determines comparison collation. Both expressions return the same entity
ID for pending requests and NULL otherwise, preserving the unique-index rule.

MySQL 5.7 documentation:

- [Literal charset and collation](https://dev.mysql.com/doc/mysql-g11n-excerpt/5.7/en/charset-literal.html)
- [Collation coercibility](https://dev.mysql.com/doc/mysql-g11n-excerpt/5.7/en/charset-collation-coercibility.html)

The comparison helper only strips the introducer from the complete reviewed
generated-column definitions in `document_change_requests` and
`technical_context_change_requests`, with the reviewed status enum, column
collation and table charset/collation present. It preserves explicit COLLATE,
BINARY, other introducers, other literals, predicates, result branches, generated
column storage/type, indexes and constraints. Existing comparison rules remain.
The validator uses the same helper for its initial comparison and its check
after incremental reapplication. Upload `tools/normalize-individual-migration-ddl.php`
alongside `tools/validate-individual-migrations.php` when updating hosting.

Regression tests load only the pure helper and the versioned schema inventory;
they never bootstrap Laravel or connect to a database. Run with PHP >= 8.3:

```sh
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/pint --test tools/normalize-individual-migration-ddl.php tests/Unit/IndividualMigrationDdlNormalizationTest.php
```

Local result: 7 tests, 51 assertions passed; Pint passed; PHP syntax checks
passed. The local default PHP 8.2 cannot load the installed Composer dependencies;
the tests were run with the available PHP 8.3.16 executable. This is a regression
test of the DDL comparison, not a new MySQL 5.7 installation run. Neither
production nor `spacedev_test` was accessed. Migrations and all validator safety,
trigger and empty-application-table checks remain unchanged.
