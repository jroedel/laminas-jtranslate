<?php

declare(strict_types=1);

namespace JTranslate\Migration;

use Laminas\Db\Adapter\Adapter;
use Laminas\Db\Adapter\AdapterInterface;
use RuntimeException;

use function count;
use function gmdate;
use function sprintf;

/**
 * Applies the library's migrations and records which ones have run.
 *
 * ## Why this exists rather than a dependency on Phinx or doctrine/migrations
 *
 * Both are better tools than this, and if the host application adopts one, this
 * should be retired in favour of it. It exists because a *library* cannot impose
 * that choice: two applications consume this code, neither has a migration
 * framework, and adding one as a hard requirement would make upgrading the
 * translation library contingent on a decision about database tooling. Two
 * migrations and a tracking table do not justify that.
 *
 * The tracking table is deliberately the library's own and namespaced to it, so
 * adopting a real migration framework later does not collide with it.
 *
 * ## The DDL-rights constraint shapes the whole design
 *
 * The web application's database user does not have DDL rights, on purpose. So
 * `apply()` is not the only path to production: `statements()` returns SQL, and the
 * command's `--pretend` prints it for somebody holding different credentials to run.
 * A runner that could only execute would be useless for the one migration that most
 * needs to happen.
 *
 * The corollary is that the tracking table may legitimately say a migration is
 * unapplied when it has in fact been run by hand. `markApplied()` exists for that,
 * and the command exposes it, because the alternative is people editing the table
 * directly.
 */
final class MigrationRunner
{
    public const TRACKING_TABLE = 'jtranslate_migration';

    /**
     * Order is explicit rather than discovered from the filesystem. A glob would make
     * the sequence depend on locale-sensitive sort order and on files nobody meant to
     * ship, and the ordering of migrations is exactly the thing that must not be
     * incidental.
     *
     * ## This list is not in numeric order, on purpose
     *
     * The seed runs **last**, after the schema migrations, because it writes
     * `phrase_hash` and correlates its rows by it — on a database created before M003
     * that column does not exist yet, and M002 fails with "Unknown column". The
     * dependency is real, so the list states it rather than the numbering implying an
     * order that does not work.
     *
     * The numbers are not renumbered to match, because `name()` is recorded in the
     * tracking table and renaming a released migration makes it pending again
     * everywhere. Numbers identify; this list sequences. Where they disagree, this
     * list is the one that runs.
     *
     * All three states reach the same schema:
     *
     * - **fresh install** — M001 creates both tables in their final shape, M003 and
     *   M004 inspect it and emit nothing, M002 seeds.
     * - **created before M003, nothing recorded** (the local capsule) — M001 no-ops on
     *   `IF NOT EXISTS`, M003 alters, M004 merges, M002 seeds.
     * - **M001 and M002 already applied** (production) — only M003 and M004 are
     *   pending and the ordering question does not arise.
     *
     * @var list<class-string<MigrationInterface>>
     */
    private const MIGRATIONS = [
        M001CreatePhraseTables::class,
        M003PhraseIdentity::class,
        M004MergeDuplicatePhrases::class,
        M002SeedUiPhrases::class,
        //After M004, which is what puts the UNIQUE constraint in place that M005's
        //merge-before-rehash order exists to satisfy, and after M002, whose seeded
        //phrases are hashed by the current PhraseIdentity and so are already normal.
        M005NormalizeLineEndings::class,
        //Order-independent — it creates a table nothing else here touches — so it goes
        //last, where a new migration goes.
        M006CreateTranslationHistory::class,
    ];

    /**
     * @param array<string, mixed> $config the resolved `jtranslate` config
     */
    public function __construct(
        private readonly AdapterInterface $db,
        private readonly array $config,
    ) {
    }

    /**
     * @return list<MigrationInterface>
     */
    public function migrations(): array
    {
        $all = [];
        foreach (self::MIGRATIONS as $class) {
            $all[] = new $class();
        }

        return $all;
    }

    /**
     * Names already recorded as applied.
     *
     * Returns an empty list when the tracking table does not exist yet, which is the
     * state of every installation before this feature shipped. Creating it here as a
     * side effect of *reading* would be surprising and would need DDL rights on a
     * status check, so it does not.
     *
     * @return array<string, string> name => applied_on
     */
    public function applied(): array
    {
        if (! $this->trackingTableExists()) {
            return [];
        }

        $result  = $this->db->query(
            sprintf('SELECT `migration`, `applied_on` FROM `%s`', self::TRACKING_TABLE),
            Adapter::QUERY_MODE_EXECUTE
        );
        $applied = [];
        foreach ($result as $row) {
            $applied[(string) $row['migration']] = (string) $row['applied_on'];
        }

        return $applied;
    }

    /**
     * @return list<MigrationInterface>
     */
    public function pending(): array
    {
        $applied = $this->applied();
        $pending = [];
        foreach ($this->migrations() as $migration) {
            if (! isset($applied[$migration->name()])) {
                $pending[] = $migration;
            }
        }

        return $pending;
    }

    /**
     * The SQL a migration would run, without running any of it.
     *
     * @return list<array{sql: string, parameters: list<mixed>}>
     */
    public function preview(MigrationInterface $migration): array
    {
        return $migration->statements($this->db, $this->config);
    }

    /**
     * Run one migration and record it.
     *
     * Not wrapped in a transaction, and that is not an oversight: MySQL commits DDL
     * implicitly, so a transaction around `CREATE TABLE` would give the appearance of
     * atomicity without the substance. Each migration is instead written so that
     * re-running it is safe — the schema step guards with `IF NOT EXISTS`, the seed
     * step inserts only what is missing — which is a property that survives a
     * failure halfway through.
     *
     * @return int the number of statements executed
     */
    public function apply(MigrationInterface $migration): int
    {
        $statements = $this->preview($migration);
        foreach ($statements as $statement) {
            $this->db->query($statement['sql'], $statement['parameters'] ?: Adapter::QUERY_MODE_EXECUTE);
        }
        $this->markApplied($migration->name());

        return count($statements);
    }

    /**
     * Record a migration as applied without running it.
     *
     * For the case the DDL-rights split creates: a DBA ran the SQL from `--pretend`,
     * and the tracking table has to be told. Also how an installation that predates
     * this feature declares its existing tables as migration 001.
     */
    public function markApplied(string $name): void
    {
        $this->ensureTrackingTable();
        $this->db->query(
            sprintf(
                'INSERT INTO `%s` (`migration`, `applied_on`) VALUES (?, ?) '
                . 'ON DUPLICATE KEY UPDATE `applied_on` = `applied_on`',
                self::TRACKING_TABLE
            ),
            [$name, gmdate('Y-m-d H:i:s')]
        );
    }

    /**
     * The tracking table's own DDL, exposed so `--pretend` can print it too.
     */
    public function trackingTableSql(): string
    {
        return sprintf(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS `%s` (
              `migration` VARCHAR(100) NOT NULL,
              `applied_on` DATETIME NOT NULL,
              PRIMARY KEY (`migration`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
            SQL,
            self::TRACKING_TABLE
        );
    }

    private function ensureTrackingTable(): void
    {
        if ($this->trackingTableExists()) {
            return;
        }

        try {
            $this->db->query($this->trackingTableSql(), Adapter::QUERY_MODE_EXECUTE);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                sprintf(
                    'Could not create the migration tracking table `%s`: %s. This needs DDL rights, which the '
                    . "web application's database user deliberately does not have — run the SQL from "
                    . '`jtranslate:migrate --pretend` with an account that does, then use '
                    . '`jtranslate:migrate --mark-applied` to record it.',
                    self::TRACKING_TABLE,
                    $e->getMessage()
                ),
                0,
                $e
            );
        }
    }

    private function trackingTableExists(): bool
    {
        //information_schema rather than SHOW TABLES so the check is one prepared
        //statement with a parameter, and reads only from the current schema
        $result = $this->db->query(
            'SELECT COUNT(*) AS c FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [self::TRACKING_TABLE]
        );

        foreach ($result as $row) {
            return ((int) $row['c']) > 0;
        }

        return false;
    }
}
