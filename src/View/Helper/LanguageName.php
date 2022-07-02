<?php

declare(strict_types=1);

namespace JTranslate\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Locale;
use SionModel\I18n\LanguageSupport;

class LanguageName extends AbstractHelper
{
    public function __invoke($language, $inLanguage = null)
    {
        if (! isset($inLanguage)) {
            $inLanguage = self::getDefaultLanguage();
        }
        $name = LanguageSupport::getLanguageName($language, $inLanguage);
        return $name ?? '';
    }

    protected static function getDefaultLanguage(): ?string
    {
        return Locale::getPrimaryLanguage(Locale::getDefault());
    }
}
