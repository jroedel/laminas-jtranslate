<?php
namespace JTranslate\I18n\Translator;
use Laminas\EventManager\AbstractListenerAggregate;
use Laminas\EventManager\Event;
use Laminas\EventManager\EventManagerInterface;
use Laminas\I18n\Translator\Translator;
use JTranslate\Model\TranslationsTable;
class TranslatorEventListener extends AbstractListenerAggregate
{
    /** @var TranslationsTable $table **/
    protected $table;
    
    /**
     * 
     * @var string[]
     */
    protected $locales;
    /**
     * 
     * @param TranslationsTable $table
     */
    public function __construct($table, $locales)
    {
        $this->table    = $table;
        $this->locales  = $locales;
    }
    
    public function attach(EventManagerInterface $events, $priority = 1)
    {
        $this->listeners[] = $events->attach(Translator::EVENT_MISSING_TRANSLATION, array($this, 'missingTranslation'), $priority);
    }
    
    /**
     * @todo Rethink my filter of locales
     *
     * @param Event $e
     *
     * @return void
     */
    public function missingTranslation(Event $e)
    {
        $params = $e->getParams();
        if (!array_key_exists($params['locale'], $this->locales)) {
            return;
        }
        $this->table->reportMissingTranslation($params);
    }
}
