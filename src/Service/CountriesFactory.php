<?php

namespace JTranslate\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;
use Laminas\Json\Json;
use JTranslate\Model\CountriesInfo;

/**
 * Factory responsible of priming the CountriesInfo service
 *
 * @author Jeff Roedel <jeff.roedel@schoenstatt-fathers.org>
 */
class CountriesFactory implements FactoryInterface
{
    /**
     * Create an object
     *
     * @inheritdoc
     */
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null)
    {
        //data vendored from mledoze/countries, see module/JTranslate/data/countries.README.txt
        $countries = Json::decode(file_get_contents(__DIR__ . '/../../data/countries.json'));

        //The locales come from config rather than being hardcoded in the model. Read
        //through the same resolved `jtranslate` config every other service here uses, so
        //an installation that adds a locale gets country names in it without touching
        //this library. The key locale is included because a country name in the key
        //locale is still a translation as far as the caller is concerned.
        $config  = $container->get('JTranslate\Config');
        $locales = $config['locales_to_translate'] ?? [];
        $keyLocale = $config['key_locale'] ?? 'en_US';
        if (! in_array($keyLocale, $locales, true)) {
            $locales[] = $keyLocale;
        }

        return new CountriesInfo($countries, $locales);
    }
}
