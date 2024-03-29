<?php

declare(strict_types=1);

namespace JTranslate\Model;

use stdClass;
use Webmozart\Assert\Assert;

use function property_exists;
use function serialize;
use function strtoupper;
use function unserialize;

class CountriesInfo
{
    /**
     * name
        common - common name in english
        official - official name in english
        native - list of all native names
        key: three-letter ISO 639-3 language code
        value: name object
        key: official - official name translation
        key: common - common name translation
        country code top-level domain (tld)
        code ISO 3166-1 alpha-2 (cca2)
        code ISO 3166-1 numeric (ccn3)
        code ISO 3166-1 alpha-3 (cca3)
        code International Olympic Committee (cioc)
        ISO 4217 currency code(s) (currency)
        calling code(s) (callingCode)
        capital city (capital)
        alternative spellings (altSpellings)
        region
        subregion
        list of official languages (languages)
        key: three-letter ISO 639-3 language code
        value: name of the language in english
        list of name translations (translations)
        key: three-letter ISO 639-3 language code
        value: name object
        key: official - official name translation
        key: common - common name translation
        latitude and longitude (latlng)
        name of residents (demonym)
        landlocked status (landlocked)
        land borders (borders)
        land area in km² (area)
     *
     * @var array[stdClass] $countries
     */
    protected $countries;

    protected $namesCache;

    protected $translationsCache;

    public const COUNTRY_NAME_COMMON   = 'common';
    public const COUNTRY_NAME_OFFICIAL = 'official';

    /**
     * @param object[] $countries
     */
    public function __construct(array $countries)
    {
        Assert::allObject($countries);
        //key array
        $return = [];
        foreach ($countries as $obj) {
            $return[$obj->cca2] = $obj;
        }
        //add scotland manually
        $scotland                              = unserialize(serialize($return['GB']));
        $scotland->name->common                = 'Scotland';
        $scotland->name->official              = 'Scotland';
        $scotland->name->native->eng->official = 'Scotland';
        $scotland->name->native->eng->common   = 'Scotland';
        $scotland->tld[0]                      = '.scot';
        $scotland->cca2                        = 'GB-SCT';
        $scotland->ccn3                        = 'GB-SCT';
        $scotland->cca3                        = '';
        $scotland->cioc                        = '';
        $scotland->capital                     = 'Edinburgh';
        $scotland->translations->deu->official = 'Schottland';
        $scotland->translations->deu->common   = 'Schottland';
        $scotland->translations->fra->official = 'Écosse';
        $scotland->translations->fra->common   = 'Écosse';
        $scotland->translations->spa->official = 'Escocia';
        $scotland->translations->spa->common   = 'Escocia';
        $scotland->translations->por->official = 'Escócia';
        $scotland->translations->por->common   = 'Escócia';
        $scotland->demonym                     = 'Scottish';
        $scotland->area                        = '77933';
        $return['GB-SCT']                      = $scotland;
        $this->countries                       = $return;
    }

    /**
     * @param string $iso2  ISO 3166-1 alpha-2 code
     */
    public function getCountry(string $iso2)
    {
        Assert::length($iso2, 2);
        $iso2 = strtoupper($iso2);
        if (isset($this->countries[$iso2])) {
            return $this->countries[$iso2];
        }
        return null;
    }

    public function getCountryNames(): array
    {
        if ($this->namesCache) {
            return $this->namesCache;
        }
        $return = [];
        foreach ($this->countries as $iso2 => $country) {
            $return[$iso2] = $country->name->common;
        }
        $this->namesCache = $return;
        return $return;
    }

    public function getTranslatedCountryNames(string $language, string $commonOrOfficial = 'common'): array
    {
        $langMap = [
            'en' => 'eng',
            'de' => 'deu',
            'es' => 'spa',
            'fr' => 'fra',
            'pt' => 'por',
            'it' => 'ita',
        ];
        Assert::keyExists($langMap, $language);
        $lang   = $langMap[$language];
        $return = [];
        foreach ($this->countries as $iso2 => $country) {
            if ($lang === 'eng') {
                $return[$iso2] = $country->name->$commonOrOfficial;
            } else {
                if (
                    property_exists($country->translations, $lang) &&
                    property_exists($country->translations->$lang, $commonOrOfficial)
                ) {
                    $return[$iso2] = $country->translations->$lang->$commonOrOfficial;
                } else { //if we don't have the requested language, just return english
                    $return[$iso2] = $country->name->$commonOrOfficial;
                }
            }
        }
        return $return;
    }

    /**
     * Returns a keyed array with the translations from
     * english to the various languages available
     * array(
     *  "United States" => array(
     *      "en_US" => "United States",
     *      "es_ES" => "Estados Unidos",
     *      ...
     *      )
     *  ...
     * )
     *
     * @todo make this list dynamic according to the locales registered
     * @return string[][]
     */
    public function getCountryNameTranslations()
    {
        $return = [];
        foreach ($this->countries as $country) {
            $return[$country->name->common] = [
                'en_US' => $country->name->common,
                'de_DE' => property_exists($country->translations, 'deu') ? $country->translations->deu->common :
                    $country->name->common,
                'pt_BR' => property_exists($country->translations, 'por') ? $country->translations->por->common :
                    $country->name->common,
                'es_ES' => property_exists($country->translations, 'spa') ? $country->translations->spa->common :
                    $country->name->common,
                'fr_FR' => property_exists($country->translations, 'fra') ? $country->translations->fra->common :
                    $country->name->common,
            ];
            if ($country->name->common !== $country->name->official) {
                $return[$country->name->official] = [
                    'en_US' => $country->name->official,
                    'de_DE' => property_exists($country->translations, 'deu') ? $country->translations->deu->official :
                        $country->name->official,
                    'pt_BR' => property_exists($country->translations, 'por') ? $country->translations->por->official :
                        $country->name->official,
                    'es_ES' => property_exists($country->translations, 'spa') ? $country->translations->spa->official :
                        $country->name->official,
                    'fr_FR' => property_exists($country->translations, 'fra') ? $country->translations->fra->official :
                        $country->name->official,
                ];
            }
        }
        return $return;
    }
}
