<?php

declare(strict_types=1);

namespace JTranslate\I18n;

use RuntimeException;

use function array_keys;
use function array_values;
use function explode;
use function implode;
use function sprintf;
use function str_replace;
use function strtolower;

/**
 * The bridge between the locales this module stores and the language codes a public
 * interface should speak.
 *
 * `trans_translations.locale` holds ICU locales — `en_US`, `pt_BR` — because that is
 * what `Laminas\I18n` resolves a catalog by, and that is not going to change. But a
 * *language* is what a caller outside the application usually means, and the region
 * subtag is noise to it: an agent asked to review the German translations should say
 * `de`, not have to learn that this particular installation happens to key German on
 * `de_DE` rather than `de_AT`.
 *
 * This site already made that distinction everywhere else. Its URLs are `/en/`, `/pt/`
 * and so on, mapped by the host application's own alias table. The API was the one
 * surface still speaking storage codes.
 *
 * ## Ambiguity is refused, not resolved
 *
 * The mapping is only total while no two configured locales share a primary subtag.
 * Add `pt_PT` beside `pt_BR` and `pt` stops identifying a translation — and the
 * failure that matters is not the lookup, it is the *write*: a caller sending `pt`
 * would have its translation stored against whichever locale happened to win, silently
 * and forever.
 *
 * So the constructor throws instead. That turns a data-corrupting configuration into a
 * loud one, which is the only safe direction: a site that genuinely needs two variants
 * of one language has outgrown language codes at its API boundary and has to say so
 * deliberately rather than discover it from a translator's bug report.
 *
 * ## Order is preserved
 *
 * `languages()` answers in the order the locales were given, because that order is
 * published — the phrase schema lists it — and a set that reshuffles between requests
 * is a diff for every consumer that stores it.
 */
final class LanguageMap
{
    /** @var array<string, string> language => locale */
    private array $localeByLanguage = [];

    /** @var array<string, string> locale => language */
    private array $languageByLocale = [];

    /**
     * @param list<string> $locales the configured locales, e.g. TranslationsTable::getLocales(true)'s keys
     * @throws RuntimeException when two locales share a primary subtag.
     */
    public function __construct(array $locales)
    {
        foreach ($locales as $locale) {
            $language = self::languageOf($locale);

            if (isset($this->localeByLanguage[$language])) {
                throw new RuntimeException(sprintf(
                    'Locales %s and %s both reduce to the language "%s", so a language code cannot '
                    . 'identify a translation on this installation. Give the API its locales instead, '
                    . 'or drop one of the two.',
                    $this->localeByLanguage[$language],
                    $locale,
                    $language
                ));
            }

            $this->localeByLanguage[$language] = $locale;
            $this->languageByLocale[$locale]   = $language;
        }
    }

    /**
     * The primary subtag of a locale, lowercased: `en_US` and `en-us` both give `en`.
     *
     * Both separators are accepted because both occur in the wild — this module stores
     * the underscore form, HTTP uses the hyphen, and a caller that sends the wrong one
     * should get the same answer rather than a mystery.
     */
    public static function languageOf(string $locale): string
    {
        $parts = explode('_', str_replace('-', '_', $locale));

        return strtolower($parts[0]);
    }

    /** @return list<string> */
    public function languages(): array
    {
        return array_keys($this->localeByLanguage);
    }

    /** @return list<string> */
    public function locales(): array
    {
        return array_values($this->localeByLanguage);
    }

    public function hasLanguage(string $language): bool
    {
        return isset($this->localeByLanguage[strtolower($language)]);
    }

    /** The locale to store against, or null when nothing is configured for that language. */
    public function localeFor(string $language): ?string
    {
        return $this->localeByLanguage[strtolower($language)] ?? null;
    }

    /**
     * The language a stored locale is exposed as, or null when it is not configured.
     *
     * Null is a real answer and not an error: `trans_translations` can hold rows for a
     * locale that has since been dropped from the configuration, and those are readable
     * through the GUI while being writable through neither surface.
     */
    public function languageFor(string $locale): ?string
    {
        return $this->languageByLocale[$locale] ?? null;
    }

    /** For an error message that has to name what would have worked. */
    public function describe(): string
    {
        return implode(', ', $this->languages());
    }
}
