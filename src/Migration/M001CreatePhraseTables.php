<?php

declare(strict_types=1);

namespace JTranslate\Migration;

use Laminas\Db\Adapter\AdapterInterface;

use function sprintf;

/**
 * Creates the two tables the library reads and writes.
 *
 * This replaces `config/database.sql.dist`, which was a copy of a phpMyAdmin export
 * and had never been runnable: it carried a trailing comma after `origin_route`, so
 * `CREATE TABLE trans_phrases` was a syntax error. Anybody who followed the old
 * install instructions got an error and fixed it by hand, which is a poor way to
 * find out.
 *
 * ## Deliberate differences from the databases already in production
 *
 * Both existing installations were created before this migration existed, so they
 * are not changed by it, and both differ from what a fresh install now gets. The
 * divergence is intentional and worth stating rather than hiding:
 *
 * - **`utf8mb4`, not `utf8mb3`.** The live tables are `utf8mb3_general_ci`, a
 *   charset MySQL has deprecated and which cannot represent anything outside the
 *   BMP. Propagating it into every future installation to preserve uniformity would
 *   be choosing the wrong default forever. Nothing in the library depends on the
 *   charset: phrase hashing happens in PHP, and the widest index over character
 *   columns is `phrase_identity`, at 432 bytes against a 3072-byte limit.
 * - **`modified_by` is an unsigned int, not `varchar(70)`.** The code has always
 *   written an integer user id here and read it back with an `(int)` cast; the live
 *   column is a string that MySQL coerces on every write. A new install should not
 *   inherit that.
 *
 * The charset half of that divergence is being closed. schoenstatt.link scheduled
 * the conversion of its live tables in `database/db7.0.sql`; patres has not, and
 * remains `utf8mb3_general_ci` until somebody schedules it there too. The
 * `modified_by` divergence is untouched by that work and still stands.
 *
 * ## Why `utf8mb4_unicode_520_ci` and not something newer
 *
 * It is the newest Unicode Collation Algorithm available on *both* engines this
 * library is expected to run on. MySQL 8's default `utf8mb4_0900_ai_ci` (UCA 9.0.0)
 * does not exist on MariaDB; MariaDB's `utf8mb4_uca1400_*` family (UCA 14.0.0) does
 * not exist before MariaDB 11, and 10.11 LTS has none of them. `_unicode_520_ci`
 * (UCA 5.2.0) exists on both. It also beats the `utf8mb4_unicode_ci` this migration
 * originally specified, which is UCA 4.0.0 from 2003 and gets `Æ`/`AE` wrong.
 *
 * Caveat for whoever moves to MariaDB 11 or MySQL 8: every pre-`uca1400` collation
 * is PAD SPACE (trailing spaces ignored in comparison) and both modern families are
 * NO PAD, so that semantic changes once more at that jump. It is a property of the
 * era, not of this particular choice.
 *
 * ## Why IF NOT EXISTS
 *
 * So that an installation which already has these tables — which is every existing
 * one — can record this migration as applied without a failure. The runner marks it
 * applied either way; the guard is what makes that honest rather than a lie.
 *
 * ## This DDL changed after release, on purpose
 *
 * `phrase`/`translation` are `TEXT` rather than `VARCHAR(2000)`, and `phrase_hash`
 * with its `UNIQUE` constraint did not originally exist. M003 and M004 explain why —
 * the short version is that a truncating phrase column silently produced 5,088
 * unrecognisable duplicate rows on schoenstatt.link, and no index over a
 * case-insensitive collation can express the byte-exact identity the translator uses.
 *
 * Editing shipped DDL is normally a mistake, because an installation that already ran
 * it will never see the change. It is safe here only because M003 and M004 exist to
 * carry exactly the same change to those installations, and because both of them
 * inspect the schema first: a database created by this migration makes each of them
 * emit nothing. The two paths converge on one shape. What must *not* happen is either
 * side drifting from the other — a clause added here needs a matching guarded clause
 * there, or a fresh install and an upgraded one stop being the same database.
 */
final class M001CreatePhraseTables implements MigrationInterface
{
    public function name(): string
    {
        return '001-create-phrase-tables';
    }

    public function describe(): string
    {
        return 'Create the phrases and translations tables';
    }

    /**
     * @param array<string, mixed> $config
     * @return list<array{sql: string, parameters: list<mixed>}>
     */
    public function statements(AdapterInterface $db, array $config): array
    {
        $phrases      = (string) ($config['phrases_table_name'] ?? 'trans_phrases');
        $translations = (string) ($config['translations_table_name'] ?? 'trans_translations');

        return [
            [
                'sql'        => sprintf(
                    <<<'SQL'
                    CREATE TABLE IF NOT EXISTS `%s` (
                      `translation_phrase_id` INT(11) NOT NULL AUTO_INCREMENT,
                      `project` VARCHAR(50) NOT NULL,
                      `text_domain` VARCHAR(50) NOT NULL,
                      `phrase` TEXT NOT NULL,
                      `phrase_hash` BINARY(32) NOT NULL,
                      `added_on` DATETIME NOT NULL,
                      `origin_route` VARCHAR(255) NULL DEFAULT NULL,
                      `retired_on` DATETIME NULL DEFAULT NULL,
                      PRIMARY KEY (`translation_phrase_id`),
                      UNIQUE KEY `phrase_identity` (`project`, `text_domain`, `phrase_hash`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
                    SQL,
                    $phrases
                ),
                'parameters' => [],
            ],
            [
                //`phrase_identity` also serves every read the library performs, all of
                //which filter on project and most of which then group by text domain —
                //they are its leftmost prefix. So there is no separate
                //(project, text_domain) index; M004 drops the one earlier versions of
                //this migration created.
                'sql'        => sprintf(
                    <<<'SQL'
                    CREATE TABLE IF NOT EXISTS `%s` (
                      `translation_id` INT(11) NOT NULL AUTO_INCREMENT,
                      `translation_phrase_id` INT(11) NOT NULL,
                      `locale` VARCHAR(20) NOT NULL,
                      `translation` TEXT NOT NULL,
                      `modified_by` INT(10) UNSIGNED NULL DEFAULT NULL,
                      `modified_on` DATETIME NOT NULL,
                      PRIMARY KEY (`translation_id`),
                      UNIQUE KEY `translation_phrase_id` (`translation_phrase_id`, `locale`),
                      CONSTRAINT `%s_phrase_fk`
                        FOREIGN KEY (`translation_phrase_id`)
                        REFERENCES `%s` (`translation_phrase_id`)
                        ON DELETE CASCADE ON UPDATE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
                    SQL,
                    $translations,
                    $translations,
                    $phrases
                ),
                'parameters' => [],
            ],
        ];
    }
}
