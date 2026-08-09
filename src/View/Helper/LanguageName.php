<?php

declare(strict_types=1);

namespace JTranslate\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Locale;

/**
 * The name of a language, written in another language.
 *
 * Backed by ICU through ext-intl, which this module already requires for
 * `\Locale::getDefault()`. It previously called `SionModel\I18n\LanguageSupport` — a
 * hand-maintained table of languages, each carrying its name in a short list of
 * "supported" display languages, with anything outside that list silently rendered in
 * English. That was the last hard dependency this module had on another application's
 * library, for data ICU already ships and keeps current.
 *
 * Two behavioural consequences, both improvements:
 *
 * - every ICU language resolves, not only the curated ones;
 * - the display language is honoured for any locale rather than falling back to
 *   English whenever it was absent from the supported list.
 *
 * The contract callers rely on is unchanged: an unrecognised code renders as an empty
 * string, never as the raw code. ICU signals "unrecognised" by echoing the input back,
 * which is the check below.
 */
class LanguageName extends AbstractHelper
{
    protected ?string $defaultLanguage = null;

    /**
     * @param string|null $language a language or locale code, e.g. 'de' or 'pt_BR'
     * @param string|null $inLanguage the language to render the name in; defaults to
     *        the request's own locale
     */
    public function __invoke($language, $inLanguage = null): string
    {
        if (! is_string($language) || '' === $language) {
            return '';
        }
        if (! is_string($inLanguage) || '' === $inLanguage) {
            $inLanguage = $this->getDefaultLanguage();
        }

        $name = Locale::getDisplayLanguage($language, $inLanguage);

        //ICU returns the input unchanged when it cannot resolve it. Callers render this
        //straight into a list of languages, where a stray 'zz' reads as data corruption
        //and an empty string reads as "unknown", which is what it is.
        if ('' === $name || $name === $language) {
            return '';
        }

        return $name;
    }

    protected function getDefaultLanguage(): string
    {
        if (null === $this->defaultLanguage) {
            $this->defaultLanguage = Locale::getPrimaryLanguage(Locale::getDefault()) ?? 'en';
        }

        return $this->defaultLanguage;
    }
}
