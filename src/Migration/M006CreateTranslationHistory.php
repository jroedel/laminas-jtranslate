<?php

declare(strict_types=1);

namespace JTranslate\Migration;

use Laminas\Db\Adapter\AdapterInterface;

use function sprintf;

/**
 * An append-only record of every translation this application destroys.
 *
 * `trans_translations` has no history. An overwrite replaces the text and nothing
 * anywhere remembers what was there, so a wrong edit is unrecoverable — and unlike a
 * wrong phrase, which `retired_on` can undo, there is no reversible form of it. That
 * shapes everything built on top: the agent translating through the v3 API dry-runs by
 * default, keeps a hundred-item review queue *before* production rather than after, and
 * treats "fill gaps, never overwrite" as a hard rule. All of that is compensation for a
 * missing table.
 *
 * ## What a row is
 *
 * One row per destroyed text, written immediately before the write that destroys it —
 * an update that replaces a translation, or a retraction that deletes one. Never on an
 * insert: there was nothing to lose. The columns split into what was lost and who took
 * it away, because both questions get asked and neither answers the other:
 *
 * - `old_translation`, `written_by`, `written_on` — the row as it stood, so a revert
 *   has the text and an attribution has the author.
 * - `operation`, `replaced_by`, `replaced_on` — the change that ended it.
 * - `notes` — why, in the words of whoever did it.
 *
 * ## The note is the reason a second agent can disagree
 *
 * `notes` is 255 characters supplied by the caller of the write, and it is attached to
 * the row recording what that write *destroyed*. So it reads as "why this text was
 * replaced", which is the sentence somebody arriving later actually needs: the current
 * text explains itself, the one that lost an argument does not.
 *
 * That is what makes a phrase's history a thread rather than a log. Two agents
 * disagreeing about a rendering leave their reasoning against the versions they
 * replaced, in order, and the third one to arrive can read why the obvious translation
 * was already tried and abandoned instead of trying it again.
 *
 * Nullable, and it stays nullable: the GUI form has no such field and a write from a
 * human through `/admin/translations` must not be refused for lacking one.
 *
 * ## No foreign key, deliberately
 *
 * Not to `trans_translations`, whose row may be gone by the time anyone reads this —
 * that is the retraction case, and the one worth recovering most. Not to
 * `trans_phrases` either: `deletePhrase()` cascades, and a history that a deletion can
 * erase is not a history. The cost is that a row can outlive its phrase, which is
 * exactly what "append-only" was asked for. `translation_phrase_id` and `locale` are
 * therefore a lookup key rather than a reference, and are indexed as one.
 *
 * ## Nothing prunes it
 *
 * By design, and it is affordable: this table grows by one row per *overwrite*, not per
 * write. Measured on schoenstatt.link, 2,902 phrases carry 8,700 translations
 * accumulated over ten years, and an overwrite is the rare case — the common ones are
 * filling a gap, which writes no history at all.
 */
final class M006CreateTranslationHistory implements MigrationInterface
{
    public function name(): string
    {
        return '006-create-translation-history';
    }

    public function describe(): string
    {
        return 'Create the append-only trans_translations_history table';
    }

    /**
     * @param array<string, mixed> $config
     * @return list<array{sql: string, parameters: list<mixed>}>
     */
    public function statements(AdapterInterface $db, array $config): array
    {
        $history = (string) ($config['translations_history_table_name'] ?? 'trans_translations_history');
        $schema  = new SchemaInspector($db);

        if ($schema->hasTable($history)) {
            return [];
        }

        return [
            [
                //`IF NOT EXISTS` as well as the check above: the check reads the schema
                //this connection can see, and M001 established the precedent that a
                //table-creating statement here has to be safe to re-run regardless.
                'sql'        => sprintf(
                    <<<SQL
                    CREATE TABLE IF NOT EXISTS `%s` (
                      `history_id` INT NOT NULL AUTO_INCREMENT,
                      `translation_phrase_id` INT NOT NULL,
                      `locale` VARCHAR(10) NOT NULL,
                      `old_translation` TEXT NOT NULL,
                      `operation` VARCHAR(10) NOT NULL,
                      `notes` VARCHAR(255) NULL DEFAULT NULL,
                      `written_by` INT NULL DEFAULT NULL,
                      `written_on` DATETIME NULL DEFAULT NULL,
                      `replaced_by` INT NULL DEFAULT NULL,
                      `replaced_on` DATETIME NOT NULL,
                      PRIMARY KEY (`history_id`),
                      KEY `phrase_locale` (`translation_phrase_id`, `locale`, `history_id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
                    SQL,
                    $history
                ),
                'parameters' => [],
            ],
        ];
    }
}
