<?php

// JTranslate/View/Helper/CountryName.php

namespace JTranslate\View\Helper;

use JTranslate\Model\CountriesInfo;
use JTranslate\View\Escape;

/**
 * A country code as its name in the current locale, optionally preceded by a flag icon.
 *
 * A plain class since 2026-09: like {@see Flag}, the only thing it took from
 * `Laminas\View\Helper\AbstractHelper` was `$this->view->escapeHtmlAttr()`, which is now
 * {@see Escape::htmlAttr()}.
 */
class CountryName
{
    public const COUNTRY_NAME_COMMON = 'common';
    public const COUNTRY_NAME_OFFICIAL = 'official';

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
        if (! key_exists($countryCode, $this->getCommonNames())) {
            return '';
        }
        $return = '';
        if ($addFlag) {
            $return = '<span class="' .
            Escape::htmlAttr('flag-icon flag-icon-' . strtolower($countryCode)) .
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
        return $this->commonNames = $this->countriesInfo->getTranslatedCountryNames(
            \Locale::getPrimaryLanguage(\Locale::getDefault()),
            CountriesInfo::COUNTRY_NAME_COMMON
        );
    }

    protected function getOfficialNames()
    {
        if ($this->officialNames) {
            return $this->officialNames;
        }
        return $this->officialNames = $this->countriesInfo->getTranslatedCountryNames(
            \Locale::getPrimaryLanguage(\Locale::getDefault()),
            CountriesInfo::COUNTRY_NAME_OFFICIAL
        );
    }
}
