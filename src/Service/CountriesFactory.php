<?php

namespace JTranslate\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;
use JTranslate\Model\CountriesInfo;
use RuntimeException;

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
        //
        //`Laminas\Json\Json::decode()` until laminas-json was removed. Two things about it
        //had to be kept: it decodes to **stdClass**, not arrays — CountriesInfo reads
        //`$obj->cca2` and `$scotland->name->common` — and it *threw* on malformed input
        //rather than returning null, which JSON_THROW_ON_ERROR reproduces. A null here
        //would surface as an unreadable error deep inside CountriesInfo instead.
        $file = __DIR__ . '/../../data/countries.json';
        $json = file_get_contents($file);
        if (false === $json) {
            throw new RuntimeException(sprintf('Cannot read the vendored country data at %s', $file));
        }
        $countries = json_decode($json, false, 512, JSON_THROW_ON_ERROR);

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
