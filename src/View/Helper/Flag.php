<?php

// JTranslate/View/Helper/Flag.php

namespace JTranslate\View\Helper;

use JTranslate\Model\CountriesInfo;
use JTranslate\View\Escape;

/**
 * A flag-icon span with the country's translated name as its tooltip.
 *
 * A plain class since 2026-09: it extended `Laminas\View\Helper\AbstractHelper` for one
 * thing only, `$this->view->escapeHtmlAttr()`, which is now {@see Escape::htmlAttr()}.
 * `$routeMatch` went with the base class — it was never assigned or read.
 */
class Flag
{
    /** @var CountriesInfo $countriesInfo */
    protected $countriesInfo;
    protected $countryNames;

    public function __construct($countriesInfo)
    {
        $this->countriesInfo = $countriesInfo;
        $this->countryNames = $this->countriesInfo->getTranslatedCountryNames(
            \Locale::getPrimaryLanguage(\Locale::getDefault())
        );
    }

    public function __invoke($countryCode)
    {
        $countryCode = strtoupper($countryCode);
        if (! key_exists($countryCode, $this->countryNames)) {
            return '';
        }

        return '<span class="' .
            Escape::htmlAttr('flag-icon flag-icon-' . strtolower($countryCode)) .
            '" data-toggle="tooltip" title="' .
            Escape::htmlAttr((string) $this->countryNames[$countryCode]) . '"></span>';
    }
}
