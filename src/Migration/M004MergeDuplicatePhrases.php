<?php

declare(strict_types=1);

namespace JTranslate\Migration;

use Laminas\Db\Adapter\AdapterInterface;

use function implode;
use function sprintf;

/**
 * Collapses duplicate phrases onto one row each, then makes duplicates impossible.
 *
 * M003 explains what created them. This is the repair, and the constraint that stops
 * it recurring. Run it after M003 — it groups by `phrase_hash`, which M003 backfills.
 *
 * ## Merging, not deleting
 *
 * Duplicate phrase rows are not interchangeable: each can carry its own
 * `trans_translations` rows, and a translator working from the admin listing had no
 * way to tell which of two identical-looking entries they were editing, so the human
 * work is spread across them arbitrarily. Measured on schoenstatt.link, 18 phrase
 * groups have more than one id carrying translations. Deleting all but the lowest id
 * would cascade real translations away.
 *
 * So for every group of rows sharing `(project, text_domain, phrase_hash)`:
 *
 * - the **lowest** `translation_phrase_id` survives, because it is the one the
 *   original insert created and the one any external reference is most likely to hold
 * - for each locale in the group, one translation row wins: the most recently
 *   modified, and on a tie the one already attached to the survivor, and on a further
 *   tie the lowest `translation_id`
 * - losing translation rows are deleted, winners are repointed at the survivor, and
 *   only then are the duplicate phrase rows removed
 *
 * The order matters. `trans_translations` has `UNIQUE (translation_phrase_id, locale)`,
 * so repointing before deleting would collide the moment two duplicates both carry the
 * same locale — which is precisely the case this migration exists for. And the foreign
 * key cascades on delete, so removing the duplicate phrase rows first would take their
 * translations with them before anything had been merged.
 *
 * ## Real tables, not temporary ones
 *
 * The three working tables are ordinary tables with a namespaced prefix, dropped at
 * the end and dropped again at the start. `CREATE TEMPORARY TABLE` would be tidier but
 * is session-scoped, and the whole point of `MigrationRunner::preview()` is that this
 * SQL can be handed to somebody else to run — possibly pasted into phpMyAdmin, which
 * does not guarantee one session per statement. Real tables also mean that a run which
 * fails halfway leaves its reasoning on disk to be inspected rather than evaporating.
 *
 * ## Verifying the hashes before trusting them
 *
 * The grouping is only as good as `phrase_hash`. To confirm the backfill agrees with
 * what the application computes, before or after running this:
 *
 * ```sql
 * SELECT COUNT(*) FROM trans_phrases WHERE phrase_hash <> UNHEX(SHA2(phrase, 256));
 * ```
 *
 * and from PHP, which is the comparison that actually matters:
 *
 * ```php
 * hash('sha256', $row['phrase'], true) === $row['phrase_hash']
 * ```
 *
 * Both were run over all 7,762 rows of schoenstatt.link's table with zero mismatches.
 *
 * ## What the constraint does and does not buy
 *
 * `UNIQUE (project, text_domain, phrase_hash)` is 432 bytes wide at most against
 * InnoDB's 3072-byte limit, and it makes `TranslationsTable`'s insert idempotent —
 * `INSERT ... ON DUPLICATE KEY UPDATE` is what turns the cached phrase index from a
 * correctness mechanism into a pure optimization. That is the real prize: an evicted
 * or stale cache can no longer cause duplicate rows, because the database refuses
 * them rather than the application remembering not to ask.
 *
 * It does not prevent a phrase from being *wrong*, and it does not remove anything
 * that has left the project. `retired_on` is for that.
 */
final class M004MergeDuplicatePhrases implements MigrationInterface
{
    private const KEEP   = 'jtranslate_dedup_keep';
    private const MEMBER = 'jtranslate_dedup_member';
    private const WIN    = 'jtranslate_dedup_win';

    /** The name given to the constraint, so a later migration can find it. */
    public const IDENTITY_INDEX = 'phrase_identity';

    /**
     * The index M001 used to create, made redundant by IDENTITY_INDEX.
     *
     * `(project, text_domain)` is the leftmost prefix of `(project, text_domain,
     * phrase_hash)`, so every query it served the new one serves too. Leaving it would
     * cost a second B-tree write on every insert for nothing.
     */
    private const REDUNDANT_INDEX = 'project_text_domain';

    public function name(): string
    {
        return '004-merge-duplicate-phrases';
    }

    public function describe(): string
    {
        return 'Merge duplicate phrases and add UNIQUE (project, text_domain, phrase_hash)';
    }

    /**
     * @param array<string, mixed> $config
     * @return list<array{sql: string, parameters: list<mixed>}>
     */
    public function statements(AdapterInterface $db, array $config): array
    {
        $phrases      = (string) ($config['phrases_table_name'] ?? 'trans_phrases');
        $translations = (string) ($config['translations_table_name'] ?? 'trans_translations');
        $schema       = new SchemaInspector($db);

        if ($schema->hasIndex($phrases, self::IDENTITY_INDEX)) {
            //Already applied. Not merely a shortcut: the merge below is written to be
            //re-runnable, but running it against a table that already refuses
            //duplicates would build three working tables to discover there is nothing
            //in them.
            return [];
        }

        $statements = [];

        foreach ([self::KEEP, self::MEMBER, self::WIN] as $working) {
            $statements[] = [
                'sql'        => sprintf('DROP TABLE IF EXISTS `%s`', $working),
                'parameters' => [],
            ];
        }

        //Only groups that actually have duplicates. On a healthy table this is empty
        //and every statement after it is a no-op over zero rows.
        $statements[] = [
            'sql'        => sprintf(
                <<<'SQL'
                CREATE TABLE `%s` AS
                SELECT `project`, `text_domain`, `phrase_hash`,
                       MIN(`translation_phrase_id`) AS `keep_id`
                FROM `%s`
                GROUP BY `project`, `text_domain`, `phrase_hash`
                HAVING COUNT(*) > 1
                SQL,
                self::KEEP,
                $phrases
            ),
            'parameters' => [],
        ];
        $statements[] = [
            'sql'        => sprintf(
                'ALTER TABLE `%s` ADD PRIMARY KEY (`keep_id`), '
                . 'ADD KEY `grp` (`project`, `text_domain`, `phrase_hash`)',
                self::KEEP
            ),
            'parameters' => [],
        ];

        //Every row of every duplicate group, survivor included — the survivor has to be
        //in here or its own translations would not take part in choosing a winner.
        $statements[] = [
            'sql'        => sprintf(
                <<<'SQL'
                CREATE TABLE `%s` AS
                SELECT p.`translation_phrase_id` AS `phrase_id`, k.`keep_id`
                FROM `%s` p
                JOIN `%s` k
                  ON k.`project` = p.`project`
                 AND k.`text_domain` = p.`text_domain`
                 AND k.`phrase_hash` = p.`phrase_hash`
                SQL,
                self::MEMBER,
                $phrases,
                self::KEEP
            ),
            'parameters' => [],
        ];
        $statements[] = [
            'sql'        => sprintf(
                'ALTER TABLE `%s` ADD PRIMARY KEY (`phrase_id`), ADD KEY `keep` (`keep_id`)',
                self::MEMBER
            ),
            'parameters' => [],
        ];

        //One winner per (surviving phrase, locale). The tie-breaks are ordered
        //deliberately: newest edit first because that is the translator's latest
        //intent; then the survivor's own row, so an unedited pair produces no
        //pointless repointing; then the lowest id, so the result is deterministic and
        //a re-run reaches the same answer.
        $statements[] = [
            'sql'        => sprintf(
                <<<'SQL'
                CREATE TABLE `%s` AS
                SELECT `translation_id` AS `win_id`
                FROM (
                  SELECT t.`translation_id`,
                         ROW_NUMBER() OVER (
                           PARTITION BY mb.`keep_id`, t.`locale`
                           ORDER BY t.`modified_on` DESC,
                                    (t.`translation_phrase_id` = mb.`keep_id`) DESC,
                                    t.`translation_id` ASC
                         ) AS `rn`
                  FROM `%s` t
                  JOIN `%s` mb ON mb.`phrase_id` = t.`translation_phrase_id`
                ) ranked
                WHERE `rn` = 1
                SQL,
                self::WIN,
                $translations,
                self::MEMBER
            ),
            'parameters' => [],
        ];
        $statements[] = [
            'sql'        => sprintf('ALTER TABLE `%s` ADD PRIMARY KEY (`win_id`)', self::WIN),
            'parameters' => [],
        ];

        //Losers first — see the class docblock on why this cannot be reordered.
        $statements[] = [
            'sql'        => sprintf(
                <<<'SQL'
                DELETE t FROM `%s` t
                JOIN `%s` mb ON mb.`phrase_id` = t.`translation_phrase_id`
                LEFT JOIN `%s` w ON w.`win_id` = t.`translation_id`
                WHERE w.`win_id` IS NULL
                SQL,
                $translations,
                self::MEMBER,
                self::WIN
            ),
            'parameters' => [],
        ];
        $statements[] = [
            'sql'        => sprintf(
                <<<'SQL'
                UPDATE `%s` t
                JOIN `%s` mb ON mb.`phrase_id` = t.`translation_phrase_id`
                SET t.`translation_phrase_id` = mb.`keep_id`
                WHERE t.`translation_phrase_id` <> mb.`keep_id`
                SQL,
                $translations,
                self::MEMBER
            ),
            'parameters' => [],
        ];
        //By now nothing references these rows, so the ON DELETE CASCADE has nothing to
        //take with it. That is the invariant the two statements above exist to
        //establish, and it is worth checking if this ever has to be debugged.
        $statements[] = [
            'sql'        => sprintf(
                <<<'SQL'
                DELETE p FROM `%s` p
                JOIN `%s` mb ON mb.`phrase_id` = p.`translation_phrase_id`
                WHERE p.`translation_phrase_id` <> mb.`keep_id`
                SQL,
                $phrases,
                self::MEMBER
            ),
            'parameters' => [],
        ];

        foreach ([self::WIN, self::MEMBER, self::KEEP] as $working) {
            $statements[] = [
                'sql'        => sprintf('DROP TABLE IF EXISTS `%s`', $working),
                'parameters' => [],
            ];
        }

        $identityClauses = [
            'MODIFY `phrase_hash` BINARY(32) NOT NULL',
            sprintf(
                'ADD UNIQUE KEY `%s` (`project`, `text_domain`, `phrase_hash`)',
                self::IDENTITY_INDEX
            ),
        ];
        if ($schema->hasIndex($phrases, self::REDUNDANT_INDEX)) {
            $identityClauses[] = sprintf('DROP KEY `%s`', self::REDUNDANT_INDEX);
        }
        $statements[] = [
            'sql'        => sprintf("ALTER TABLE `%s`\n  ", $phrases) . implode(",\n  ", $identityClauses),
            'parameters' => [],
        ];

        return $statements;
    }
}
