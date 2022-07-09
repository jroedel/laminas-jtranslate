<?php

declare(strict_types=1);

namespace JTranslate\View\Helper;

use JTranslate\Model\CountriesInfo;
use Laminas\View\Helper\AbstractHelper;
use Locale;
use Webmozart\Assert\Assert;

use function array_key_exists;
use function strtolower;
use function strtoupper;

final class Flag extends AbstractHelper
{
    protected array $countryNames;

    public function __construct(private CountriesInfo $countriesInfo)
    {
        $this->countryNames = $this->countriesInfo->getTranslatedCountryNames(
            Locale::getPrimaryLanguage(Locale::getDefault())
        );
        Assert::notEmpty($this->countryNames);
    }

    public function __invoke(string $countryCode): string
    {
        $countryCode = strtoupper($countryCode);
        if (! array_key_exists($countryCode, $this->countryNames)) {
            return '';
        }

        return '<span class="'
            . $this->view->escapeHtmlAttr('flag-icon flag-icon-' . strtolower($countryCode))
            . '" data-toggle="tooltip" title="'
            . $this->view->escapeHtmlAttr($this->countryNames[$countryCode]) . '"></span>';
    }
}
