<?php
// JTranslate/View/Helper/Flag.php

namespace JTranslate\View\Helper;

use Zend\View\Helper\AbstractHelper;
use JTranslate\Model\CountriesInfo;

class CountryName extends AbstractHelper
{
    const COUNTRY_NAME_COMMON = 'common';
    const COUNTRY_NAME_OFFICIAL = 'official';

    /** @var CountriesInfo $countriesInfo */
    protected $countriesInfo;

    protected $commonNames;
    protected $officialNames;

    public function __construct($countriesInfo)
    {
        $this->countriesInfo = $countriesInfo;
    }
    
    public function __invoke($countryCode, $addFlag = false, $commonOrOfficial = 'common')
    {
        $countryCode = strtoupper($countryCode);
        if (!key_exists($countryCode, $this->getCommonNames())) {
            return '';
        }
        $return = '';
        if ($addFlag) {
            $return = '<span class="'.
            $this->view->escapeHtmlAttr('flag-icon flag-icon-'.strtolower($countryCode)).
            '"></span>&nbsp;';
        }
        if ($commonOrOfficial === 'official') {
            $return .= $this->getOfficialNames()[$countryCode];
        } else {
            $return .= $this->getCommonNames()[$countryCode];
        }
        return $return;
    }

    protected function getCommonNames()
    {
        if ($this->commonNames) {
            return $this->commonNames;
        }
        return $this->commonNames =
        $this->countriesInfo->getTranslatedCountryNames(\Locale::getPrimaryLanguage(\Locale::getDefault()),
            CountriesInfo::COUNTRY_NAME_COMMON);
    }
    
    protected function getOfficialNames()
    {
        if ($this->officialNames) {
            return $this->officialNames;
        }
        return $this->officialNames = 
            $this->countriesInfo->getTranslatedCountryNames(\Locale::getPrimaryLanguage(\Locale::getDefault()), 
                CountriesInfo::COUNTRY_NAME_OFFICIAL);
    }

}
