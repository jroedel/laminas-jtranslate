<?php

declare(strict_types=1);

namespace JTranslate\Migration;

use JTranslate\Model\PhraseIdentity;
use Laminas\Db\Adapter\AdapterInterface;
use RuntimeException;

use function array_key_exists;
use function gmdate;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Pre-feeds the phrases JTranslate's own GUI displays, with their translations.
 *
 * Without this, a fresh installation's translation GUI is English-only in its own
 * chrome until somebody browses every screen in every locale and then translates
 * what the runtime discovery path recorded. The data has always existed; it just
 * used to ship as four compiled `language/*.lang.php` catalogs — build artifacts
 * checked into source, and stale ones: 9 phrases against the 26 the database held.
 *
 * ## Idempotent per phrase *and* per locale
 *
 * Re-running fills gaps and touches nothing that exists. That matters more than it
 * looks:
 *
 * - An existing installation already has most of these phrases, with translations a
 *   human may have improved since. Overwriting them with the shipped values would
 *   silently discard real editorial work, so an existing `(phrase, locale)` row is
 *   never updated — only missing ones are inserted.
 * - `data/ui-phrases.php` gains entries over time. Running this again after an
 *   upgrade is the intended way to pick them up.
 *
 * The consequence is that this migration is safe to re-run by hand even though the
 * runner will only apply it once automatically.
 *
 * ## Scoped by project, keyed by the configured key locale
 *
 * `project_name` is per-installation, so nothing here can be static SQL — the rows
 * must be built against the resolved config. Each phrase's key-locale value is the
 * phrase itself, which is what the runtime discovery path writes, so it is derived
 * rather than duplicated in the data file.
 *
 * ## Correlated by hash, never by phrase text
 *
 * Every statement below that has to find a phrase row finds it by `phrase_hash`, and
 * every "does this already exist" lookup keys on the hash too. Matching on `phrase`
 * would compare under `utf8mb4_unicode_520_ci` — case-insensitive, accent-insensitive,
 * PAD SPACE — so seeding `Save` would silently claim an existing row for `save` and
 * attach this migration's translations to it. The hash is over the raw bytes, which is
 * the same identity `Laminas\I18n\Translator` uses on its catalog keys and the same one
 * `phrase_identity` enforces. See M003 for the longer version of this argument.
 */
final class M002SeedUiPhrases implements MigrationInterface
{
    /** This library's own text domain, which is also its namespace. */
    public const TEXT_DOMAIN = 'JTranslate';

    private const DATA_FILE = __DIR__ . '/../../data/ui-phrases.php';

    public function name(): string
    {
        return '002-seed-ui-phrases';
    }

    public function describe(): string
    {
        return "Pre-feed the GUI's own phrases and translations (idempotent; safe to re-run)";
    }

    /**
     * @param array<string, mixed> $config
     * @return list<array{sql: string, parameters: list<mixed>}>
     */
    public function statements(AdapterInterface $db, array $config): array
    {
        $project = $config['project_name'] ?? null;
        if (! is_string($project) || '' === $project) {
            throw new RuntimeException(
                "jtranslate.project_name is not configured, so there is no project to seed phrases for. "
                . 'Several applications may share one phrase table and this string is what separates them; '
                . 'guessing it would put rows under the wrong owner.'
            );
        }

        $keyLocale    = is_string($config['key_locale'] ?? null) ? $config['key_locale'] : 'en_US';
        $phrasesTable = (string) ($config['phrases_table_name'] ?? 'trans_phrases');
        $transTable   = (string) ($config['translations_table_name'] ?? 'trans_translations');
        $now          = gmdate('Y-m-d H:i:s');

        //MigrationRunner runs this last, after the schema migrations, precisely so the
        //column is there. It can still be missing in one situation: --pretend does not
        //execute anything, so on a database created before M003 the preview of *this*
        //migration runs against a schema M003's printed-but-unexecuted SQL has not
        //changed yet.
        //
        //That is a limitation of previewing a data migration across a schema boundary
        //and cannot be designed away — this migration has to read the table to know
        //what is missing. What it can do is say so, because the alternative is a raw
        //"Unknown column 'phrase_hash' in 'SELECT'" that names neither the cause nor
        //the fix.
        if (! (new SchemaInspector($db))->hasColumn($phrasesTable, 'phrase_hash')) {
            throw new RuntimeException(sprintf(
                "`%s` has no `phrase_hash` column, so the seed cannot be built yet. Apply "
                . '003-phrase-identity first and then run this again — with --pretend, take the SQL '
                . "printed for 003 and 004, run it with an account that has DDL rights, record it with "
                . '`jtranslate:migrate --mark-applied=003-phrase-identity` (and 004), then preview this one.',
                $phrasesTable
            ));
        }

        $seed = require self::DATA_FILE;
        if (! is_array($seed)) {
            throw new RuntimeException(sprintf('%s did not return an array', self::DATA_FILE));
        }

        $existing   = $this->existingPhraseHashes($db, $phrasesTable, $project);
        $translated = $this->existingTranslationLocales($db, $phrasesTable, $transTable, $project);

        $statements = [];
        foreach ($seed as $phrase => $translations) {
            $phrase = (string) $phrase;
            $hex    = PhraseIdentity::hex($phrase);

            if (! array_key_exists($hex, $existing)) {
                $statements[] = [
                    'sql'        => sprintf(
                        'INSERT INTO `%s` (`project`, `text_domain`, `phrase`, `phrase_hash`, `added_on`) '
                        . 'VALUES (?, ?, ?, UNHEX(?), ?)',
                        $phrasesTable
                    ),
                    'parameters' => [$project, self::TEXT_DOMAIN, $phrase, $hex, $now],
                ];
            }
            //The new id is not known until the insert above runs, so the translation
            //rows below correlate by phrase hash through a subselect rather than by
            //id. That keeps this a flat statement list --pretend can print verbatim
            //and a DBA can paste, which an id-capturing loop could not.
            //
            //UNHEX(?) rather than a raw 32-byte parameter so the printed SQL stays
            //copy-pasteable text. A binary parameter would render as mojibake in
            //--pretend output and could not be run by hand at all.

            $wanted = [$keyLocale => $phrase];
            foreach (is_array($translations) ? $translations : [] as $locale => $translation) {
                if (! is_string($translation) || '' === $translation) {
                    continue;
                }
                $wanted[(string) $locale] = $translation;
            }

            foreach ($wanted as $locale => $translation) {
                if (isset($translated[$hex][$locale])) {
                    continue;
                }
                $statements[] = [
                    'sql'        => sprintf(
                        'INSERT INTO `%s` (`translation_phrase_id`, `locale`, `translation`, `modified_on`) '
                        . 'SELECT `translation_phrase_id`, ?, ?, ? FROM `%s` '
                        . 'WHERE `project` = ? AND `text_domain` = ? AND `phrase_hash` = UNHEX(?) LIMIT 1',
                        $transTable,
                        $phrasesTable
                    ),
                    'parameters' => [$locale, $translation, $now, $project, self::TEXT_DOMAIN, $hex],
                ];
            }
        }

        return $statements;
    }

    /**
     * @return array<string, int> hex phrase hash => id
     */
    private function existingPhraseHashes(AdapterInterface $db, string $table, string $project): array
    {
        $result = $db->query(
            sprintf(
                'SELECT `translation_phrase_id`, LOWER(HEX(`phrase_hash`)) AS `hex_hash` FROM `%s` '
                . 'WHERE `project` = ? AND `text_domain` = ?',
                $table
            ),
            [$project, self::TEXT_DOMAIN]
        );

        $found = [];
        foreach ($result as $row) {
            $found[(string) $row['hex_hash']] = (int) $row['translation_phrase_id'];
        }

        return $found;
    }

    /**
     * @return array<string, array<string, true>> hex phrase hash => locale => true
     */
    private function existingTranslationLocales(
        AdapterInterface $db,
        string $phrasesTable,
        string $transTable,
        string $project
    ): array {
        $result = $db->query(
            sprintf(
                'SELECT LOWER(HEX(p.`phrase_hash`)) AS `hex_hash`, t.`locale` FROM `%s` p '
                . 'JOIN `%s` t ON p.`translation_phrase_id` = t.`translation_phrase_id` '
                . 'WHERE p.`project` = ? AND p.`text_domain` = ?',
                $phrasesTable,
                $transTable
            ),
            [$project, self::TEXT_DOMAIN]
        );

        $found = [];
        foreach ($result as $row) {
            $found[(string) $row['hex_hash']][(string) $row['locale']] = true;
        }

        return $found;
    }
}
