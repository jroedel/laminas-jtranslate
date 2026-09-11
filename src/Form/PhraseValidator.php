<?php

declare(strict_types=1);

namespace JTranslate\Form;

use JTranslate\I18n\LanguageMap;
use Laminas\Db\Adapter\Adapter;
use SionModel\Validator\StringLength;
use SionModel\Form\Validation\FormSpecification;
use SionModel\Form\Validation\InputFilter as Engine;

/**
 * {@see EditPhraseForm}'s rules as something with no browser behind it, built with no
 * MVC, no session and no rendered form.
 *
 * ## Why this is the library's job and not the application's
 *
 * A host application that validates a translation outside the GUI — an HTTP API, a
 * console importer, a queue worker — needs exactly what the GUI enforces and nothing
 * else. Doing that from outside means knowing three things that are none of its
 * business:
 *
 * 1. `EditPhraseForm`'s constructor signature, including that its `$locales` argument
 *    is a `[code => display name]` map whose values are never read for validation;
 * 2. that the `security` CSRF input has to be removed, and that its name is `security`;
 * 3. that the `RecordExists` validator on `phraseId` needs a db adapter — which, until
 *    2026-08-14, meant knowing that `getInputFilterSpecification()` read
 *    `GlobalAdapterFeature::getStaticAdapter()` and that the static registry was
 *    populated by `JUser\Module::onBootstrap()` under laminas-mvc and by nothing
 *    anywhere else. The form takes the adapter as a constructor argument now, so this
 *    one is an ordinary dependency rather than a trap.
 *
 * schoenstatt.link's `/api/v3/phrases` reproduced all three, and that is the wrong
 * place for them: every one is an internal detail of this module, and the admin GUI —
 * `JTranslateController`, both forms, three `.phtml` — is the part of JTranslate most
 * likely to be rewritten rather than ported (see the 3.0 notes in README.md). When
 * `EditPhraseForm` is reshaped, a caller that built it by hand breaks and nothing in
 * this repository notices.
 *
 * So the contract moves here. What this class promises is "the rules a translator is
 * held to, as something you can put data through"; how those rules are expressed is free
 * to change behind it, and 3.0 has to keep the promise while replacing the form.
 *
 * ## It is not a Laminas\Validator
 *
 * The name is kept from the caller that introduced it and matches its sibling on the
 * association side of that application. It validates, but it does not implement
 * `SionModel\Validator\ValidatorInterface` and is not usable in a validator chain.
 *
 * ## The static adapter, retired
 *
 * This class used to publish its injected adapter into `GlobalAdapterFeature`'s static
 * registry before building the form, because the form read it from there. The section
 * that stood here said plainly that a process-global write was the wrong answer and
 * that this was one named place rather than a fix. It is gone as of 2026-08-14:
 * `EditPhraseForm` takes an `Adapter`, so the adapter travels down the constructor and
 * no code in either module touches the registry. Nothing about this class's promise
 * changed — only that it no longer has to keep a global honest to deliver it.
 */
final class PhraseValidator
{
    /**
     * The one input a caller with no browser session is not held to.
     *
     * `SionModel\Validator\Csrf` reads a `Laminas\Session\Container`, so leaving it in
     * place would be a fatal rather than a validation failure — and would refuse every
     * such caller regardless of what it submitted.
     */
    public const SESSION_ONLY_INPUT = 'security';

    private ?LanguageMap $languages = null;

    /**
     * @param list<string> $locales the writable locale codes, from TranslationsTable::getLocales(true)
     */
    public function __construct(
        private readonly array $locales,
        private readonly string $phrasesTableName,
        private readonly string $translationsTableName,
        private readonly Adapter $adapter
    ) {
    }

    /**
     * The same set as {@see self::writableLocales()}, addressed by language code.
     *
     * A public interface should say `de`, not `de_DE`: the region subtag is an artefact
     * of how this module keys catalogs, and no caller outside the application should
     * have to learn that German happens to be stored as `de_DE` here rather than
     * `de_AT`. Storage keeps the locale; the boundary translates.
     *
     * Built lazily, because it throws on an ambiguous configuration — two locales
     * sharing a primary subtag — and a caller that only wants the locales should not be
     * stopped by that. See {@see LanguageMap}.
     */
    public function languages(): LanguageMap
    {
        return $this->languages ??= new LanguageMap($this->locales);
    }

    /**
     * The locale codes a caller may write.
     *
     * `getLocales(true)` — the key locale included — because `updatePhrase()` iterates
     * exactly that set, so a locale this list omitted would be silently discarded
     * rather than refused.
     *
     * **This comes from the merged configuration, never from a module default.** This
     * module's own config names three locales to translate; an application's
     * `jtranslate.global.php` adds its own and laminas merges the two by *appending*.
     * On schoenstatt.link the real answer is five including the key locale. Anything
     * that hardcodes the list is wrong somewhere.
     *
     * @return list<string>
     */
    public function writableLocales(): array
    {
        return $this->locales;
    }

    /**
     * A fresh engine over the form's rules, every call.
     *
     * Never shared: the engine holds the data and the messages of whatever was last
     * validated through it, so handing the same instance to two requests would leak one
     * caller's submission into another's. This object is stateless and safe to share; what
     * it builds is not, which is why this is a method and not a property.
     *
     * The CSRF rule is removed by dropping its key. A specification is plain data, so the
     * exemption is an `unset` where the assembled laminas filter this replaced needed
     * `has()` and `remove()` on an object graph.
     */
    public function inputFilter(): Engine
    {
        $spec = $this->specification();
        unset($spec[self::SESSION_ONLY_INPUT]);

        return Engine::withRules($spec);
    }

    /**
     * How long a translation may be, for a caller that wants to publish the rule rather
     * than apply it.
     *
     * Read out of the specification rather than returned as a constant, so the number a
     * public schema advertises cannot drift from the number a submission is held to. The
     * schema endpoint used to dig this out of the assembled filter's validator chain —
     * `$filter->get($locale)->getValidatorChain()->getValidators()` — which meant the
     * application knew that translations are keyed by locale and that the bound is a
     * `StringLength`. Both are this module's business.
     *
     * Null when there is no writable locale, or when its rules name no length bound.
     */
    public function maxTranslationLength(): ?int
    {
        $locales = $this->writableLocales();
        if ([] === $locales) {
            return null;
        }

        /** @var array<string, mixed> $rules */
        $rules = $this->specification()[$locales[0]] ?? [];
        /** @var list<array<string, mixed>> $validators */
        $validators = $rules['validators'] ?? [];

        foreach ($validators as $validator) {
            //Either spelling: a specification may name a validator by class or by the
            //short name the plugin manager resolves.
            $name = (string) ($validator['name'] ?? '');
            if (StringLength::class !== $name && 'StringLength' !== $name) {
                continue;
            }

            $max = $validator['options']['max'] ?? null;

            return null === $max ? null : (int) $max;
        }

        return null;
    }

    /**
     * The form's whole specification, CSRF included — what a caller reads when it wants to
     * publish a rule rather than apply one.
     *
     * @return array<string, mixed>
     */
    public function specification(): array
    {
        return FormSpecification::of(new EditPhraseForm(
            $this->localeMap(),
            $this->phrasesTableName,
            $this->translationsTableName,
            $this->adapter
        ));
    }

    /**
     * The form wants `[code => display name]` and reads only the keys when it builds
     * its input filter specification, so the display name is the code. Nothing renders
     * this form.
     *
     * @return array<string, string>
     */
    private function localeMap(): array
    {
        $map = [];
        foreach ($this->locales as $locale) {
            $map[$locale] = $locale;
        }

        return $map;
    }
}
