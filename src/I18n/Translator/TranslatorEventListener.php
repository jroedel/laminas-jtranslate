<?php

namespace JTranslate\I18n\Translator;

use Laminas\EventManager\AbstractListenerAggregate;
use Laminas\EventManager\Event;
use Laminas\EventManager\EventManagerInterface;
use Laminas\I18n\Translator\Translator;
use JTranslate\Model\TranslationsTable;

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
 * has it and no event fires. Only a genuinely never-before-seen string reaches here in
 * the key locale, which is exactly the case that was being dropped.
 *
 * Both wiring sites must pass the same thing — `JTranslate\Module::onBootstrap()` and,
 * in the Symfony-side host, `App\Laminas\TranslatorConfigurator`. A discrepancy would
 * mean discovery worked under one front controller and not the other.
 */
class TranslatorEventListener extends AbstractListenerAggregate
{
    /** @var TranslationsTable $table **/
    protected $table;

    /**
     * The locales a miss is worth recording in, as a map keyed by locale.
     *
     * Keyed, not a list — the test below is a `key_exists()`. Build it with
     * `TranslationsTable::getLocales(true)`; see the class docblock for why the `true`
     * is load-bearing.
     *
     * @var string[]
     */
    protected $locales;

    /**
     * @param TranslationsTable $table
     * @param string[] $locales keyed by locale, from getLocales(true)
     */
    public function __construct($table, $locales)
    {
        $this->table    = $table;
        $this->locales  = $locales;
    }

    public function attach(EventManagerInterface $events, $priority = 1)
    {
        $this->listeners[] = $events->attach(
            Translator::EVENT_MISSING_TRANSLATION,
            [$this, 'missingTranslation'],
            $priority
        );
    }

    /**
     * @param Event $e
     */
    public function missingTranslation(Event $e)
    {
        $params = $e->getParams();
        if (! key_exists($params['locale'], $this->locales)) {
            return;
        }
        $this->table->reportMissingTranslation($params);
    }
}
