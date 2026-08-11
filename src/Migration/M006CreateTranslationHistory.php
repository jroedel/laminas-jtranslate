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
 * One row per event that changes what a translator would see. Two of the three destroy
 * text and are written immediately before the write that does it — an update that
 * replaces a translation, or a retraction that deletes one. Never on an insert: there
 * was nothing to lose.
 *
 * The third is `retire`, which destroys nothing: it records that the *phrase* left the
 * worklist because the application knows nothing renders it any more. It carries no
 * language (`locale` is `''`, which no translation row can hold) and no previous text,
 * and its `notes` is the whole content. It is here rather than in a table of its own
 * because it answers a question about the same object, asked by the same reader, at the
 * same moment: a phrase with four good translations vanishes from the listing, and
 * without this the only honest answer to "what happened to it" is "something retired it
 * and nothing wrote down what".
 *
 * The columns split into what was lost and who took it away, because both questions get
 * asked and neither answers the other:
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
 * ## The thread key is the phrase *hash*, not its id
 *
 * `KEY thread (project, phrase_hash, locale, history_id)` — the one lookup this table
 * is for, and the reason it is not keyed on `translation_phrase_id`.
 *
 * A phrase id is not stable. M004 merges duplicate rows onto the lowest id and drops
 * the rest; M005 merges again when normalization collapses two; `deletePhrase()` removes
 * a row outright and the next render *rediscovers* the same English string as a new row
 * with a new id. Every one of those is a routine event in this table's history, and each
 * would silently cut a thread in half — leaving the earlier reasoning in the database,
 * attached to an id nothing asks for again, at exactly the moment somebody is trying to
 * find out why a translation keeps being changed back.
 *
 * The hash survives all of them, because it *is* the phrase: two rows with the same hash
 * are the same string by definition, which is what M004's UNIQUE constraint means. So a
 * rediscovered phrase inherits its own history, and a merge concatenates the histories of
 * the rows it merged rather than orphaning all but one.
 *
 * `project` leads the key and is not negotiable: four projects share these tables and a
 * phrase's text can be private to one of them.
 *
 * `text_domain` is stored but deliberately **not** in the key. The same string in
 * `Schoenstatt` and in `default` is one string with one translation problem, and the
 * table already treats it that way — it copies existing translations onto a new row when
 * a phrase appears in a second domain. A thread that split by domain would show an agent
 * half the argument.
 *
 * `translation_phrase_id` is kept, indexed, and is a back-reference rather than a key: it
 * answers "which row was this, at the time" for anyone reconstructing events, and it
 * costs one integer.
 *
 * ### Which means a phrase-level event can appear more than once in a thread
 *
 * Worth stating outright, because it reads as a bug the first time. The same string in two
 * text domains is **two live rows sharing one hash** — routine, and what an application
 * that renders a label through two domains produces every time. Each row is its own place
 * on the translator's worklist, so each is retired separately and writes its own `retire`
 * row here; and since the thread is keyed on the hash, a read from *either* id returns
 * *both*.
 *
 * Two retirements with two notes is therefore the correct answer for a string that existed
 * twice, and it is not a retry recorded twice: {@see TranslationsTable::retirePhraseById()}
 * acts only on a row in the opposite state, so a repeat writes nothing at all. `text_domain`
 * and `translation_phrase_id` on the row are what tell a reader which one each event was
 * about — which is the second reason both columns are here and the first reason they are not
 * in the key.
 *
 * The operational corollary is the part that costs something if it is missed: **retiring one
 * row does not retire its sibling.** A caller clearing a string has to retire every row of
 * it, or the string keeps asking for work through the domain it did not touch.
 *
 * One consequence to record: an identity change like M005's rewrites `phrase_hash` in
 * `trans_phrases`, and any future one must rewrite it here too or every thread older than
 * the migration disappears. M005 predates this table and had nothing to do.
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
                      `project` VARCHAR(50) NOT NULL,
                      `phrase_hash` BINARY(32) NOT NULL,
                      `locale` VARCHAR(10) NOT NULL,
                      `text_domain` VARCHAR(50) NOT NULL,
                      `translation_phrase_id` INT NOT NULL,
                      `old_translation` TEXT NOT NULL,
                      `operation` VARCHAR(10) NOT NULL,
                      `notes` VARCHAR(255) NULL DEFAULT NULL,
                      `written_by` INT NULL DEFAULT NULL,
                      `written_on` DATETIME NULL DEFAULT NULL,
                      `replaced_by` INT NULL DEFAULT NULL,
                      `replaced_on` DATETIME NOT NULL,
                      PRIMARY KEY (`history_id`),
                      KEY `thread` (`project`, `phrase_hash`, `locale`, `history_id`),
                      KEY `phrase` (`translation_phrase_id`, `history_id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
                    SQL,
                    $history
                ),
                'parameters' => [],
            ],
        ];
    }
}
