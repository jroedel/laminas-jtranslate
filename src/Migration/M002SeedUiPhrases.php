<?php

declare(strict_types=1);

namespace JTranslate\Migration;

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

        $seed = require self::DATA_FILE;
        if (! is_array($seed)) {
            throw new RuntimeException(sprintf('%s did not return an array', self::DATA_FILE));
        }

        $existing   = $this->existingPhraseIds($db, $phrasesTable, $project);
        $translated = $this->existingTranslationLocales($db, $phrasesTable, $transTable, $project);

        $statements = [];
        foreach ($seed as $phrase => $translations) {
            $phrase = (string) $phrase;

            if (! array_key_exists($phrase, $existing)) {
                $statements[] = [
                    'sql'        => sprintf(
                        'INSERT INTO `%s` (`project`, `text_domain`, `phrase`, `added_on`) VALUES (?, ?, ?, ?)',
                        $phrasesTable
                    ),
                    'parameters' => [$project, self::TEXT_DOMAIN, $phrase, $now],
                ];
            }
            //The new id is not known until the insert above runs, so the translation
            //rows below correlate by phrase text through a subselect rather than by
            //id. That keeps this a flat statement list --pretend can print verbatim
            //and a DBA can paste, which an id-capturing loop could not.

            $wanted = [$keyLocale => $phrase];
            foreach (is_array($translations) ? $translations : [] as $locale => $translation) {
                if (! is_string($translation) || '' === $translation) {
                    continue;
                }
                $wanted[(string) $locale] = $translation;
            }

            foreach ($wanted as $locale => $translation) {
                if (isset($translated[$phrase][$locale])) {
                    continue;
                }
                $statements[] = [
                    'sql'        => sprintf(
                        'INSERT INTO `%s` (`translation_phrase_id`, `locale`, `translation`, `modified_on`) '
                        . 'SELECT `translation_phrase_id`, ?, ?, ? FROM `%s` '
                        . 'WHERE `project` = ? AND `text_domain` = ? AND `phrase` = ? LIMIT 1',
                        $transTable,
                        $phrasesTable
                    ),
                    'parameters' => [$locale, $translation, $now, $project, self::TEXT_DOMAIN, $phrase],
                ];
            }
        }

        return $statements;
    }

    /**
     * @return array<string, int> phrase => id
     */
    private function existingPhraseIds(AdapterInterface $db, string $table, string $project): array
    {
        $result = $db->query(
            sprintf(
                'SELECT `translation_phrase_id`, `phrase` FROM `%s` WHERE `project` = ? AND `text_domain` = ?',
                $table
            ),
            [$project, self::TEXT_DOMAIN]
        );

        $found = [];
        foreach ($result as $row) {
            $found[(string) $row['phrase']] = (int) $row['translation_phrase_id'];
        }

        return $found;
    }

    /**
     * @return array<string, array<string, true>> phrase => locale => true
     */
    private function existingTranslationLocales(
        AdapterInterface $db,
        string $phrasesTable,
        string $transTable,
        string $project
    ): array {
        $result = $db->query(
            sprintf(
                'SELECT p.`phrase`, t.`locale` FROM `%s` p '
                . 'JOIN `%s` t ON p.`translation_phrase_id` = t.`translation_phrase_id` '
                . 'WHERE p.`project` = ? AND p.`text_domain` = ?',
                $phrasesTable,
                $transTable
            ),
            [$project, self::TEXT_DOMAIN]
        );

        $found = [];
        foreach ($result as $row) {
            $found[(string) $row['phrase']][(string) $row['locale']] = true;
        }

        return $found;
    }
}
