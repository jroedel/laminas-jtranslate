<?php

declare(strict_types=1);

namespace JTranslate\I18n\Translator;

use JTranslate\Model\TranslationsTable;
use Laminas\EventManager\AbstractListenerAggregate;
use Laminas\EventManager\Event;
use Laminas\EventManager\EventManagerInterface;
use Laminas\I18n\Translator\Translator;

use function array_key_exists;

class TranslatorEventListener extends AbstractListenerAggregate
{
    public function __construct(private TranslationsTable $table, private array $locales)
    {
    }

    /**
     * @param int $priority
     */
    public function attach(EventManagerInterface $events, $priority = 1): void
    {
        $this->listeners[] = $events->attach(
            Translator::EVENT_MISSING_TRANSLATION,
            [$this, 'missingTranslation'],
            $priority
        );
    }

    /**
     * @todo Rethink my filter of locales
     */
    public function missingTranslation(Event $e): void
    {
        $params = $e->getParams();
        if (! array_key_exists($params['locale'], $this->locales)) {
            return;
        }
        $this->table->reportMissingTranslation($params);
    }
}
