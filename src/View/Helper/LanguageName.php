<?php
namespace JTranslate\View\Helper;

use Zend\View\Helper\AbstractHelper;
use SionModel\I18n\LanguageSupport;

class LanguageName extends AbstractHelper
{
    protected $languageSupport;
    protected $defaultLanguage;
    
    public function __invoke($language, $inLanguage = null)
    {
        if (!isset($inLanguage)) {
            $inLanguage = $this->getDefaultLanguage();
        }
        $name = $this->getLanguageSupport()->getLanguageName($language, $inLanguage);
        if (!isset($name)) {
            return '';
        }
        return $name;
    }
    
    public function getLanguageSupport()
    {
        if (!isset($this->languageSupport)) {
            $this->languageSupport = new LanguageSupport();
        }
        return $this->languageSupport;
    }
    
    protected function getDefaultLanguage()
    {
        if (!isset($this->defaultLanguage)) {
            $this->defaultLanguage = \Locale::getPrimaryLanguage(\Locale::getDefault());
        }
        return $this->defaultLanguage;
    }
}
