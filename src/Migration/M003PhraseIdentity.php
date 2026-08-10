<?php

declare(strict_types=1);

namespace JTranslate\Migration;

use Laminas\Db\Adapter\AdapterInterface;

use function implode;
use function sprintf;

/**
 * Gives a phrase a lossless column and an exact identity.
 *
 * This is the schema half of the fix for the defect that produced 77% of the rows in
 * schoenstatt.link's phrase table. M004 is the data half and must run after it.
 *
 * ## The defect
 *
 * `phrase` was `VARCHAR(2000)`. A phrase longer than that — an association's
 * directions text, a page body, anything the site passes through `translate()` that
 * is content rather than a label — was **silently truncated on insert** under the
 * non-strict `sql_mode` these databases were created with. From that moment the row
 * could never be recognised again:
 *
 * - `TranslationsTable::reportMissingTranslation()` tests a hash of the *full* phrase
 * - the stored row holds the *truncated* phrase, so the phrase index holds a hash of
 *   the truncated text
 * - the two can never match, so every render of that page inserted another row
 *
 * That is not a race and no amount of cache correctness would have prevented it. It
 * is a deterministic insert loop, one row per pageview, for as long as the page is
 * served. Measured on schoenstatt.link: 5,088 rows holding **two** distinct strings,
 * every one of them exactly 2,000 characters long.
 *
 * On a modern engine the same phrase raises `Data too long for column 'phrase'`
 * instead — MariaDB 10.11 defaults to `STRICT_TRANS_TABLES` — so the bug did not go
 * away, it changed from unbounded growth into an exception raised at end of request.
 * Widening the column is what actually retires it, and it is why this migration is a
 * prerequisite for the uniqueness constraint rather than a tidy-up beside it: a
 * `UNIQUE` index over a truncating column converts the insert loop into a duplicate
 * key error on every render, which is louder but no more correct.
 *
 * `translation` is widened for the same reason. A phrase that does not fit cannot
 * have a translation that fits, and 5,087 translation rows sat at exactly 2,000
 * characters.
 *
 * ## Why a hash column, and why it is not a prefix index
 *
 * The obvious constraint — `UNIQUE (project, text_domain, phrase(255))` — fails this
 * data twice.
 *
 * The first failure is truncation, and is the one that is easy to see: two distinct
 * German directions texts of 517 and 772 characters share their first 255, so the
 * index would refuse the second. MySQL's key length limit will not stretch to 2,000
 * characters of `utf8mb4`, let alone `TEXT`, so no prefix is long enough.
 *
 * The second failure is collation, and it is the decisive one. `phrase` is
 * `utf8mb4_unicode_520_ci`: case-insensitive, accent-insensitive, PAD SPACE. A
 * `UNIQUE` index under that collation treats `Save`, `save` and `Save ` as one value,
 * while `Laminas\I18n\Translator` compares its catalog keys byte for byte and treats
 * them as three. The constraint would refuse phrases that are genuinely distinct to
 * the only consumer that matters, and it would do it silently — the phrase would
 * simply never become translatable.
 *
 * A hash over the raw bytes reproduces the translator's own equality relation
 * exactly. No collation does.
 *
 * ## Why SHA-256, and why 32 raw bytes
 *
 * The library already hashed phrases with `md5()` for its in-memory membership set,
 * where a collision is invisible and harmless. Writing that hash into a `UNIQUE`
 * constraint changes what a collision costs: a phrase text is partly editorial
 * content, so it is partly attacker-chosen, and md5 chosen-prefix collisions are
 * cheap. The consequence would be a phrase that can never be added. SHA-256 costs
 * the same to compute and sixteen more bytes to store.
 *
 * `BINARY(32)` rather than a hex string, because `CHAR(64)` under `utf8mb4` occupies
 * **256 bytes** in the index — `CHAR` is padded to the charset's maximum bytes per
 * character — and compares under a collation rather than by `memcmp`.
 *
 * ## Why the backfill hashes in SQL when the application refuses to
 *
 * `TranslationsTable::getPhraseIndex()` documents, correctly, that the library must
 * not ask MySQL for `MD5(phrase)`: the server hashes the value in the *column's*
 * character set, so a later `CONVERT TO CHARACTER SET` — or a connection whose
 * charset forces a conversion — silently changes every hash, and the application's
 * hashes stop matching the stored ones forever.
 *
 * That argument is about a *standing* dependency, and a stored generated column would
 * have exactly that shape. A one-time backfill does not: it runs immediately after
 * the statement above has established that the column is `utf8mb4`, whose bytes are
 * UTF-8 and therefore identical to what PHP hashes. The equivalence was checked
 * against all 7,762 rows of schoenstatt.link's table before this was written — zero
 * mismatches — and it is re-checkable at any time with the query in the class
 * docblock of M004.
 *
 * Generating one `UPDATE` per row instead would make `--pretend` print a hundred
 * thousand statements on a real installation, which is not a reviewable artifact.
 *
 * ## Why `retired_on` is here rather than in its own migration
 *
 * Because it is the same `ALTER TABLE`, and on a large table the rebuild is the
 * expensive part. Its purpose is described on `TranslationsTable::retire()`; the
 * short version is that nothing was ever removed from these tables because
 * `deletePhrase()` cascades translations away with no history to recover them from,
 * so doing nothing was always the rational choice and the table only grew.
 *
 * ## `modified_by`
 *
 * `VARCHAR(70)` holding an integer user id that the code writes as an int and reads
 * back with an `(int)` cast. M001 creates it as `INT UNSIGNED`; this closes the
 * divergence for the two installations that predate that. Verified free of
 * non-numeric values before conversion — if that is not true of some other
 * installation, the `ALTER` will say so rather than coerce.
 *
 * ## Emits nothing on a fresh install
 *
 * M001 now creates both tables in their final shape, so on a new database every
 * clause below is already satisfied and this migration produces an empty statement
 * list. It exists for the databases that were created before it. See SchemaInspector
 * for why the guard is a lookup rather than `ADD COLUMN IF NOT EXISTS`.
 */
final class M003PhraseIdentity implements MigrationInterface
{
    public function name(): string
    {
        return '003-phrase-identity';
    }

    public function describe(): string
    {
        return 'Widen phrase/translation to TEXT, add phrase_hash and retired_on';
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

        $statements = [];

        //One ALTER carrying every phrase-table clause, not one per clause. Each of
        //these rebuilds the table under ALGORITHM=COPY, and a rebuild blocks writes
        //for its whole duration — so three ALTERs is three stalls of a site that
        //inserts into this table from the request path.
        //
        //phrase_hash is added NULL and made NOT NULL by M004, because there is no
        //value to give the existing rows until the backfill below has run.
        $phraseClauses = [];
        if ('text' !== $schema->columnType($phrases, 'phrase')) {
            $phraseClauses[] = 'MODIFY `phrase` TEXT NOT NULL';
        }
        if (! $schema->hasColumn($phrases, 'phrase_hash')) {
            $phraseClauses[] = 'ADD COLUMN `phrase_hash` BINARY(32) NULL DEFAULT NULL AFTER `phrase`';
        }
        if (! $schema->hasColumn($phrases, 'retired_on')) {
            $phraseClauses[] = 'ADD COLUMN `retired_on` DATETIME NULL DEFAULT NULL AFTER `origin_route`';
        }
        if ([] !== $phraseClauses) {
            $statements[] = [
                'sql'        => sprintf('ALTER TABLE `%s`%s  ', $phrases, "\n")
                    . implode(",\n  ", $phraseClauses),
                'parameters' => [],
            ];
        }

        //Unconditional, and guarded by its own WHERE rather than by the inspector: the
        //column may exist and still hold NULLs, because a previous run of this
        //migration may have failed between the ALTER and the backfill. DDL commits
        //implicitly, so that state is reachable.
        //
        //See the class docblock for why this one hash is computed by the server while
        //every other hash in this library is computed by PHP.
        $statements[] = [
            'sql'        => sprintf(
                'UPDATE `%s` SET `phrase_hash` = UNHEX(SHA2(`phrase`, 256)) WHERE `phrase_hash` IS NULL',
                $phrases
            ),
            'parameters' => [],
        ];

        $translationClauses = [];
        if ('text' !== $schema->columnType($translations, 'translation')) {
            $translationClauses[] = 'MODIFY `translation` TEXT NOT NULL';
        }
        if ('int' !== $schema->columnType($translations, 'modified_by')) {
            $translationClauses[] = 'MODIFY `modified_by` INT(10) UNSIGNED NULL DEFAULT NULL';
        }
        if ([] !== $translationClauses) {
            $statements[] = [
                'sql'        => sprintf('ALTER TABLE `%s`%s  ', $translations, "\n")
                    . implode(",\n  ", $translationClauses),
                'parameters' => [],
            ];
        }

        return $statements;
    }
}
