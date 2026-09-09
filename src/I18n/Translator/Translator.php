<?php

declare(strict_types=1);

namespace JTranslate\I18n\Translator;

use Laminas\Translator\TranslatorInterface as LaminasTranslatorInterface;
use Laminas\Validator\Translator\TranslatorInterface as ValidatorTranslatorInterface;
use Locale;

use function is_array;
use function is_file;
use function is_string;
use function sprintf;

/**
 * The translator, without laminas-i18n.
 *
 * This module already owns the phrase table, the export that compiles it into
 * `<domain>/<locale>.lang.php` catalogs, and the discovery path that records a phrase
 * nobody has seen before. What it did not own was the lookup, which came from
 * `Laminas\I18n\Translator\Translator` — a class whose catalogue loaders, plugin manager,
 * gettext and INI formats, cache layer and event manager this application used none of.
 * What it used is here: **a message, a text domain, a locale, and a fallback.**
 *
 * ## Two interfaces, and both are deliberate
 *
 * `Laminas\Translator\TranslatorInterface` is an interface-only package and the shape
 * laminas-validator 3 will require. `Laminas\Validator\Translator\TranslatorInterface` is
 * the one the installed 2.x `AbstractValidator::setDefaultTranslator()` type-hints, and is
 * `@deprecated` for exactly that reason. The two declare the same `translate()`, so one
 * class satisfies both: validators keep working today and keep working after their v3
 * upgrade, and the `Laminas\Validator\Translator\Translator` adapter that used to bridge
 * them — itself `@deprecated` — is gone.
 *
 * ## Lookup order, transcribed rather than invented
 *
 * `translate()` reproduces laminas-i18n's semantics, because five years of catalogs and a
 * phrase table were built against them:
 *
 * - The requested locale's catalog for the domain, then — if the result is absent **or an
 *   empty string** — the fallback locale's. An empty translation counts as missing; the
 *   phrase table holds those and they must read as the source text, not as nothing.
 * - **No language-only widening.** `es_ES` does not fall back to `es`. The catalogs are
 *   written with full locale names and a partial match would silently serve a different
 *   installation's wording.
 * - A miss notifies the listeners **once per locale tried**, so a lookup that misses in
 *   `de_DE` and again in the fallback `en_US` reports both. That is what lets an English
 *   page view discover a phrase, which {@see TranslatorEventListener} explains at length.
 * - A listener returning a string ends the lookup and that string is the translation.
 *   Nothing uses that today; it is kept because the discovery listener's contract is
 *   "return a replacement or nothing", and narrowing it would be a silent behaviour change.
 *
 * ## Catalogs are loaded once per domain and locale, lazily
 *
 * A page touches two or three domains, and the catalogs are plain PHP arrays that OPcache
 * has already compiled. There is no cache layer here on purpose: the file *is* the cache,
 * and laminas-i18n's optional one existed for the formats that need parsing.
 */
final class Translator implements LaminasTranslatorInterface, ValidatorTranslatorInterface
{
    public const DEFAULT_TEXT_DOMAIN = 'default';

    /**
     * Where each text domain's catalogs live.
     *
     * @var array<string, list<array{baseDir: string, pattern: string}>>
     */
    private array $patterns = [];

    /**
     * Loaded catalogs, `[textDomain][locale] => [message => translation]`.
     *
     * An empty array is a loaded catalog with nothing in it, which is different from an
     * absent key and must not trigger a second load.
     *
     * @var array<string, array<string, array<string, string>>>
     */
    private array $messages = [];

    /** @var list<callable(string, string, string): ?string> */
    private array $missListeners = [];

    private ?string $locale = null;

    private ?string $fallbackLocale = null;

    /** Defaults to `Locale::getDefault()`, which is what laminas-i18n did. */
    public function getLocale(): string
    {
        return $this->locale ?? Locale::getDefault();
    }

    public function setLocale(string $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    public function getFallbackLocale(): ?string
    {
        return $this->fallbackLocale;
    }

    /**
     * The locale an untranslated phrase reads in. Set it to the key locale — the language
     * the phrases themselves are written in — or a miss comes out as the raw source text
     * in a page of another language.
     */
    public function setFallbackLocale(?string $locale): self
    {
        $this->fallbackLocale = $locale;

        return $this;
    }

    /**
     * Register a directory of catalogs for one text domain.
     *
     * The signature is laminas-i18n's, including `$type`, so the two call sites read
     * unchanged. Only `phpArray` was ever passed and only `phpArray` is understood; any
     * other type is ignored rather than fatal, because a translator that throws takes down
     * a page over a missing translation, which is the wrong trade every time.
     */
    public function addTranslationFilePattern(
        string $type,
        string $baseDir,
        string $pattern,
        string $textDomain = self::DEFAULT_TEXT_DOMAIN
    ): self {
        if ('phpArray' !== $type) {
            return $this;
        }
        $this->patterns[$textDomain][] = ['baseDir' => $baseDir, 'pattern' => $pattern];
        unset($this->messages[$textDomain]);

        return $this;
    }

    /**
     * Add messages directly, with no file behind them.
     *
     * This is what laminas-i18n needed a loader plugin manager and `addRemoteTranslations`
     * for. One test uses it, to prove a form renders without touching the filesystem.
     *
     * @param array<string, string> $messages
     */
    public function addMessages(string $textDomain, string $locale, array $messages): self
    {
        $this->load($textDomain, $locale);
        $this->messages[$textDomain][$locale] = $messages + $this->messages[$textDomain][$locale];

        return $this;
    }

    /**
     * Every message of one domain, loading the catalog if it has not been read yet.
     *
     * The translation GUI uses it to show what a locale currently has, which is the one
     * caller that wants the catalog rather than a single lookup.
     *
     * @return array<string, string>
     */
    public function getAllMessages(
        string $textDomain = self::DEFAULT_TEXT_DOMAIN,
        ?string $locale = null
    ): array {
        $locale ??= $this->getLocale();
        $this->load($textDomain, $locale);

        return $this->messages[$textDomain][$locale];
    }

    /**
     * Be told about a phrase this translator could not find.
     *
     * The listener receives the message, the locale actually tried and the text domain,
     * and may return a replacement string or null. {@see TranslatorEventListener} is the
     * one that matters: it records the miss, which is the only way a phrase ever enters
     * the database.
     *
     * @param callable(string, string, string): ?string $listener
     */
    public function onMissingTranslation(callable $listener): self
    {
        $this->missListeners[] = $listener;

        return $this;
    }

    /**
     * Forget every miss listener.
     *
     * The host attaches its real listener on the *first* miss rather than up front,
     * because building the phrase table needs services that would recurse back into this
     * translator. That bootstrap listener uses this to stand down once it has run.
     */
    public function clearMissingTranslationListeners(): self
    {
        $this->missListeners = [];

        return $this;
    }

    public function hasMissingTranslationListeners(): bool
    {
        return [] !== $this->missListeners;
    }

    /**
     * @param string $message
     * @param string $textDomain
     * @param string|null $locale
     * @return string
     */
    public function translate(
        $message,
        $textDomain = self::DEFAULT_TEXT_DOMAIN,
        $locale = null
    ) {
        if ('' === $message) {
            return '';
        }
        $locale       = is_string($locale) && '' !== $locale ? $locale : $this->getLocale();
        $textDomain   = '' === $textDomain ? self::DEFAULT_TEXT_DOMAIN : $textDomain;
        $translation  = $this->lookup($message, $locale, $textDomain);

        if (null !== $translation && '' !== $translation) {
            return $translation;
        }

        $fallback = $this->fallbackLocale;
        if (null !== $fallback && $locale !== $fallback) {
            return $this->translate($message, $textDomain, $fallback);
        }

        return $message;
    }

    /**
     * Nothing in this application calls it — measured across the app and all three
     * shared libraries at the laminas-i18n removal, 2026-09-09, zero call sites — and the
     * catalogs have no plural forms to answer with. The interface requires it, so it
     * answers the only thing it honestly can: the singular or the plural, translated as
     * an ordinary message, by the English rule. A locale needing anything else should get
     * a real implementation rather than this one silently doing the wrong thing.
     *
     * @param string $singular
     * @param string $plural
     * @param int $number
     * @param string $textDomain
     * @param string|null $locale
     * @return string
     */
    public function translatePlural(
        $singular,
        $plural,
        $number,
        $textDomain = self::DEFAULT_TEXT_DOMAIN,
        $locale = null
    ) {
        return $this->translate(1 === (int) $number ? $singular : $plural, $textDomain, $locale);
    }

    /** The catalog answer, or null when the domain has nothing for this message. */
    private function lookup(string $message, string $locale, string $textDomain): ?string
    {
        $this->load($textDomain, $locale);

        $found = $this->messages[$textDomain][$locale][$message] ?? null;
        if (is_string($found)) {
            return $found;
        }

        foreach ($this->missListeners as $listener) {
            $replacement = $listener($message, $locale, $textDomain);
            if (is_string($replacement)) {
                return $replacement;
            }
        }

        return null;
    }

    /** Read every registered catalog for this domain and locale, once. */
    private function load(string $textDomain, string $locale): void
    {
        if (isset($this->messages[$textDomain][$locale])) {
            return;
        }
        $messages = [];
        foreach ($this->patterns[$textDomain] ?? [] as $pattern) {
            $file = $pattern['baseDir'] . '/' . sprintf($pattern['pattern'], $locale);
            if (! is_file($file)) {
                continue;
            }
            /** @var mixed $loaded */
            $loaded = include $file;
            if (is_array($loaded)) {
                //later patterns win, which is the order laminas-i18n merged them in
                foreach ($loaded as $key => $value) {
                    if (is_string($key) && is_string($value)) {
                        $messages[$key] = $value;
                    }
                }
            }
        }
        $this->messages[$textDomain][$locale] = $messages;
    }
}
