<?php
// JTranslate/View/Helper/Flag.php

namespace JTranslate\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use JTranslate\Model\CountriesInfo;

class Flag extends AbstractHelper
{
    protected $routeMatch;
    
    /** @var CountriesInfo $countriesInfo */
    protected $countriesInfo;
    
    protected $countryNames;

    public function __construct($countriesInfo)
    {
        $this->countriesInfo = $countriesInfo;
        $this->countryNames = $this->countriesInfo->getTranslatedCountryNames(\Locale::getPrimaryLanguage(\Locale::getDefault()));
    }

    public function __invoke($countryCode)
    {
        $countryCode = strtoupper($countryCode);
        if (!array_key_exists($countryCode, $this->countryNames)) {
            return '';
        }
        
        return '<span class="'.
            $this->view->escapeHtmlAttr('flag-icon flag-icon-'.strtolower($countryCode)).
            '" data-toggle="tooltip" title="'.
            $this->view->escapeHtmlAttr($this->countryNames[$countryCode]).'"></span>';
    }
}
