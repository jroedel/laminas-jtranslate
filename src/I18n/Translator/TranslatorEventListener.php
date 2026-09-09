<?php

declare(strict_types=1);

namespace JTranslate\I18n\Translator;

use JTranslate\Model\TranslationsTable;

use function array_key_exists;

/**
 * Records phrases the translator asked for and could not find.
 *
 * This is the only path by which a phrase ever enters `trans_phrases`, so what it
 * chooses to ignore is not a detail: an ignored phrase is one nobody can ever
 * translate, because the row a translation hangs off is never created.
 *
 * ## The locale filter, and why it must include the key locale
 *
 * The list is a guard against recording lookups in locales this installation does not
 * translate — a visitor's `Accept-Language` can ask for anything, and `fr_CA` misses
 * are noise rather than work.
 *
 * It used to be built from `getLocales()`, which omits the **key** locale. The
 * consequence was easy to miss and hard to live with: an English page view could never
 * discover a phrase. A string that appeared only on English pages stayed unknown to the
 * database indefinitely, and the site's own recovery story — "a deleted phrase comes
 * back the next time a page renders it" — silently held only for visitors browsing in
 * Spanish, German, Portuguese or Italian.
 *
 * It now takes `getLocales(true)`. The cost is nil in steady state: every phrase in the
 * database already has an auto-inserted key-locale translation, so the compiled catalog
 * has it and no miss is reported. Only a genuinely never-before-seen string reaches here
 * in the key locale, which is exactly the case that was being dropped.
 *
 * ## A plain callable, not a listener aggregate
 *
 * Until 2026-09 this extended `Laminas\EventManager\AbstractListenerAggregate` and hung
 * off `Laminas\I18n\Translator\Translator::EVENT_MISSING_TRANSLATION`. Both packages are
 * gone from the translation path; {@see Translator::onMissingTranslation()} takes this
 * object directly. The three event parameters became three arguments and nothing else
 * changed — including the return contract: null means "no replacement, record it", and a
 * string would be used as the translation.
 */
final class TranslatorEventListener
{
    /**
     * @param TranslationsTable $table where a miss is recorded
     * @param array<string, mixed> $locales the locales a miss is worth recording in, as a
     *        map **keyed by locale** — the test below is an `array_key_exists()`. Build it
     *        with `TranslationsTable::getLocales(true)`; see the class docblock for why
     *        the `true` is load-bearing.
     */
    public function __construct(
        private readonly TranslationsTable $table,
        private readonly array $locales
    ) {
    }

    /** The shape {@see Translator::onMissingTranslation()} calls. */
    public function __invoke(string $message, string $locale, string $textDomain): ?string
    {
        $this->missingTranslation($message, $locale, $textDomain);

        return null;
    }

    /**
     * Record one miss, unless it is in a locale this installation does not translate.
     *
     * Returns nothing: the phrase is *queued*, and `TranslationsTable::flush()` writes it
     * at the end of the request. A host that never flushes discovers nothing, which is
     * why the flush is wired at the same moment the listener is.
     */
    public function missingTranslation(string $message, string $locale, string $textDomain): void
    {
        if (! array_key_exists($locale, $this->locales)) {
            return;
        }
        $this->table->reportMissingTranslation([
            'message'     => $message,
            'locale'      => $locale,
            'text_domain' => $textDomain,
        ]);
    }
}
