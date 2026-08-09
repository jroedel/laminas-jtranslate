<?php

declare(strict_types=1);

namespace JTranslate\Form;

use Laminas\Db\Adapter\Adapter;
use Laminas\Db\TableGateway\Feature\GlobalAdapterFeature;
use Laminas\InputFilter\InputFilterInterface;

/**
 * {@see EditPhraseForm}'s rules as something with no browser behind it: one
 * `InputFilter`, built with no MVC, no session and no rendered form.
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
 * 3. that `getInputFilterSpecification()` calls
 *    `GlobalAdapterFeature::getStaticAdapter()` while building the `RecordExists`
 *    validator on `phraseId`, so the static registry must be populated first — which
 *    `JUser\Module::onBootstrap()` does under laminas-mvc and nothing does anywhere
 *    else.
 *
 * schoenstatt.link's `/api/v3/phrases` reproduced all three, and that is the wrong
 * place for them: every one is an internal detail of this module, and the admin GUI —
 * `JTranslateController`, both forms, three `.phtml` — is the part of JTranslate most
 * likely to be rewritten rather than ported (see the 3.0 notes in README.md). When
 * `EditPhraseForm` is reshaped, a caller that built it by hand breaks and nothing in
 * this repository notices.
 *
 * So the contract moves here. What this class promises is "the rules a translator is
 * held to, as an InputFilter"; how those rules are expressed is free to change behind
 * it, and 3.0 has to keep the promise while replacing the form.
 *
 * ## It is not a Laminas\Validator
 *
 * The name is kept from the caller that introduced it and matches its sibling on the
 * association side of that application. It validates, but it does not implement
 * `Laminas\Validator\ValidatorInterface` and is not usable in a validator chain.
 *
 * ## The static adapter, honestly
 *
 * Populating `GlobalAdapterFeature` is a process-global write, and it is done here
 * rather than left to callers precisely because it is easy to forget and produces a
 * `RuntimeException` from deep inside laminas-db when it is. It is safe in the
 * direction that matters: the value written is the adapter this module was configured
 * with, so an application that later populates the registry itself writes the same
 * object. It is still the reason this is one named place instead of a line copied
 * wherever a filter is needed.
 */
final class PhraseValidator
{
    /**
     * The one input a caller with no browser session is not held to.
     *
     * `Laminas\Validator\Csrf` reads a `Laminas\Session\Container`, so leaving it in
     * place would be a fatal rather than a validation failure — and would refuse every
     * such caller regardless of what it submitted.
     */
    public const SESSION_ONLY_INPUT = 'security';

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
     * A fresh input filter, every call.
     *
     * Never shared: an `InputFilter` holds the data and the messages of whatever was
     * last validated through it, so handing the same instance to two requests would
     * leak one caller's submission into another's. This object is stateless and safe
     * to share; the filter it builds is not, which is why this is a method and not a
     * property.
     *
     * @return InputFilterInterface<array<string, mixed>>
     */
    public function inputFilter(): InputFilterInterface
    {
        GlobalAdapterFeature::setStaticAdapter($this->adapter);

        $form = new EditPhraseForm(
            $this->localeMap(),
            $this->phrasesTableName,
            $this->translationsTableName
        );

        $filter = $form->getInputFilter();
        if ($filter->has(self::SESSION_ONLY_INPUT)) {
            $filter->remove(self::SESSION_ONLY_INPUT);
        }

        return $filter;
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
