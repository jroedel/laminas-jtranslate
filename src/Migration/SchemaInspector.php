<?php

declare(strict_types=1);

namespace JTranslate\Migration;

use Laminas\Db\Adapter\AdapterInterface;

/**
 * What the current schema already has, so a migration can emit only the clauses that
 * are still missing.
 *
 * ## Why this is needed at all
 *
 * `MigrationRunner` states that every migration must be safe to re-run, because DDL
 * commits implicitly and a migration that fails halfway leaves the schema partly
 * changed with nothing recorded. `CREATE TABLE IF NOT EXISTS` gives M001 that
 * property for free. `ALTER TABLE ... ADD COLUMN` has no such spelling that is
 * portable — MariaDB has `IF NOT EXISTS`, MySQL does not — so the guard has to be a
 * lookup, and the interface explicitly permits one: `statements()` receives the
 * adapter so a migration can look before it writes.
 *
 * It is also what lets M001 create tables in their final shape while M003 and M004
 * still exist for the installations that predate them. A fresh install runs all four
 * and the last two correctly emit nothing.
 *
 * Reads `information_schema` rather than `SHOW COLUMNS`/`SHOW INDEX` so that every
 * query is a prepared statement with parameters and is scoped to the current schema.
 */
final class SchemaInspector
{
    public function __construct(private readonly AdapterInterface $db)
    {
    }

    public function hasTable(string $table): bool
    {
        return $this->count(
            'SELECT COUNT(*) AS c FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        ) > 0;
    }

    public function hasColumn(string $table, string $column): bool
    {
        return $this->count(
            'SELECT COUNT(*) AS c FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        ) > 0;
    }

    public function hasIndex(string $table, string $index): bool
    {
        return $this->count(
            'SELECT COUNT(*) AS c FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $index]
        ) > 0;
    }

    /**
     * The declared type of a column, lower-cased, or null when there is no such column.
     *
     * `DATA_TYPE`, not `COLUMN_TYPE`: the caller asks "is this still a varchar", not
     * "is this varchar(2000)", and the length is exactly the part that varies between
     * the two installations this library runs on.
     */
    public function columnType(string $table, string $column): ?string
    {
        $result = $this->db->query(
            'SELECT LOWER(`DATA_TYPE`) AS t FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );

        foreach ($result as $row) {
            return (string) $row['t'];
        }

        return null;
    }

    /**
     * @param list<mixed> $parameters
     */
    private function count(string $sql, array $parameters): int
    {
        $result = $this->db->query($sql, $parameters);
        foreach ($result as $row) {
            return (int) $row['c'];
        }

        return 0;
    }
}
