<?php

declare(strict_types=1);

namespace JTranslate\Migration;

use Laminas\Db\Adapter\AdapterInterface;

use function sprintf;

/**
 * Rewrites CRLF phrases to LF and merges whatever that collapses.
 *
 * `PhraseIdentity` now normalizes line endings before hashing, so a phrase and the
 * same phrase with CRLF endings are one phrase. Until this runs, the table disagrees
 * with the code in two ways at once: rows whose stored `phrase_hash` was taken over
 * the CRLF bytes no longer match what the application computes — so the row exists,
 * cannot be found, and the next render inserts a duplicate — and the pairs the
 * normalization exists to collapse are still two rows.
 *
 * Four of 2,904 rows are affected on schoenstatt.link, two of them the pair the
 * change request names:
 *
 *     Only delete an assignment if it was created by mistake! …   \n   and   \r\n
 *     Only delete a person if he/she was created by mistake! …    \n   and   \r\n
 *
 * ## Why it is written as "merge everything, again"
 *
 * The obvious shape is "find CRLF rows, look for an LF twin, merge that pair". It is
 * wrong in two cases that both exist in a shared table: two CRLF rows that normalize
 * onto each other with no LF row anywhere, and a CRLF row whose twin lives in another
 * project or text domain and must *not* be touched. Grouping every row by
 * `(project, text_domain, normalized hash)` and merging groups of more than one is
 * the same rule M004 applies, states the intent directly, and is a no-op over the
 * 2,900 rows that were already unique.
 *
 * The merge itself is M004's, statement for statement, including the order that
 * cannot be changed: `trans_translations` has `UNIQUE (translation_phrase_id, locale)`
 * so losing rows must go before winners are repointed, and the foreign key cascades
 * on delete so the phrase rows must go last. See M004's docblock for the reasoning
 * and for the tie-breaks.
 *
 * ## Rehashing comes after merging
 *
 * `UNIQUE (project, text_domain, phrase_hash)` is in force by now, so an UPDATE that
 * rewrote a hash onto one already present would be refused. Merging first is what
 * guarantees the survivor is alone in its group by the time its hash changes.
 *
 * The rewrite sets `phrase` and `phrase_hash` in one statement, deliberately: a row
 * whose text disagrees with what its own hash was taken over is a row nothing can
 * look up again, which is the defect M003 was written to repair.
 *
 * ## Re-runnable
 *
 * The rewrite selects on `phrase LIKE '%\r%'` and the merge groups on a normalized
 * hash that is already normal, so a second run finds nothing and changes nothing.
 *
 * ## It covers every project in the table, and that is a decision
 *
 * `jtranslate_migration` has no project column: a migration is a statement about the
 * database, not about one installation, and M003 already backfilled `phrase_hash` for
 * every row of every project on the same reasoning. This follows that precedent — the
 * alternative, scoping to `project_name`, leaves rows in a state no installation's
 * code agrees with and records the migration as done anyway.
 *
 * The consequence is worth stating plainly, because it reaches code that is not in
 * this repository. Four projects share this table on schoenstatt.link (`patres`,
 * `Patres`, `Schoenstatt`, `texts`) and `patres` runs JTranslate 1.0.x, which has no
 * `PhraseIdentity` and no normalization. Two of its rows have their line endings
 * rewritten here. If a 1.0.x template emits CRLF it will miss the rewritten catalog
 * key and insert its own row again — a duplicate of the kind this migration exists to
 * remove, in a project that has not yet been upgraded. Two rows, one sentence each,
 * and it resolves itself when that installation moves to this line.
 */
final class M005NormalizeLineEndings implements MigrationInterface
{
    private const KEEP   = 'jtranslate_eol_keep';
    private const MEMBER = 'jtranslate_eol_member';
    private const WIN    = 'jtranslate_eol_win';

    /**
     * PhraseIdentity::normalize() in SQL, over the column named by the caller.
     *
     * CRLF before lone CR, for the reason given there: the other order turns every
     * CRLF into a blank line.
     */
    private const NORMALIZED = 'REPLACE(REPLACE(%s, CHAR(13,10), CHAR(10)), CHAR(13), CHAR(10))';

    public function name(): string
    {
        return '005-normalize-line-endings';
    }

    public function describe(): string
    {
        return 'Rewrite CRLF phrases to LF, rehash them, and merge what that collapses';
    }

    /**
     * @param array<string, mixed> $config
     * @return list<array{sql: string, parameters: list<mixed>}>
     */
    public function statements(AdapterInterface $db, array $config): array
    {
        $phrases      = (string) ($config['phrases_table_name'] ?? 'trans_phrases');
        $translations = (string) ($config['translations_table_name'] ?? 'trans_translations');

        $normalizedPhrase = sprintf(self::NORMALIZED, 'p.`phrase`');
        $normalizedHash   = sprintf('UNHEX(SHA2(%s, 256))', $normalizedPhrase);

        $statements = [];

        foreach ([self::KEEP, self::MEMBER, self::WIN] as $working) {
            $statements[] = [
                'sql'        => sprintf('DROP TABLE IF EXISTS `%s`', $working),
                'parameters' => [],
            ];
        }

        //Groups of more than one row that share a normalized identity. Empty on a
        //table that has no CRLF phrases, which makes every statement after it a no-op
        //over zero rows.
        $statements[] = [
            'sql'        => sprintf(
                <<<SQL
                CREATE TABLE `%s` AS
                SELECT p.`project`, p.`text_domain`,
                       $normalizedHash AS `norm_hash`,
                       MIN(p.`translation_phrase_id`) AS `keep_id`
                FROM `%s` p
                GROUP BY p.`project`, p.`text_domain`, $normalizedHash
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
                . 'ADD KEY `grp` (`project`, `text_domain`, `norm_hash`)',
                self::KEEP
            ),
            'parameters' => [],
        ];

        $statements[] = [
            'sql'        => sprintf(
                <<<SQL
                CREATE TABLE `%s` AS
                SELECT p.`translation_phrase_id` AS `phrase_id`, k.`keep_id`
                FROM `%s` p
                JOIN `%s` k
                  ON k.`project` = p.`project`
                 AND k.`text_domain` = p.`text_domain`
                 AND k.`norm_hash` = $normalizedHash
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

        //Text and hash together. Whatever survived the merge is alone in its group, so
        //the UNIQUE constraint has nothing to refuse.
        //
        //MySQL evaluates a multi-column SET left to right, so `phrase_hash` is computed
        //over the *already rewritten* `phrase`. That is harmless rather than lucky:
        //normalization is idempotent, so normalizing the normalized text is the same
        //text, and the hash is the one the application will compute. Reordering the two
        //assignments would also be correct; relying on neither order would not.
        $statements[] = [
            'sql'        => sprintf(
                'UPDATE `%s` p SET p.`phrase` = %s, p.`phrase_hash` = %s '
                . "WHERE p.`phrase` LIKE CONCAT('%%', CHAR(13), '%%')",
                $phrases,
                $normalizedPhrase,
                $normalizedHash
            ),
            'parameters' => [],
        ];

        return $statements;
    }
}
