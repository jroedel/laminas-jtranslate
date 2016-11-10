<?php
namespace JTranslation\Model;

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
     * @var array[\stdClass] $countries
     */
    protected $countries;
    
    protected $namesCache;
    
    protected $translationsCache;
    
    const COUNTRY_NAME_COMMON = 'common';
    const COUNTRY_NAME_OFFICIAL = 'official';
    
    public function __construct($countries)
    {
        //key array
        $return = array();
        foreach ($countries as $obj) {
            $return[$obj->cca2] = $obj;
        }
        $this->countries = $return;
    }
    
    /**
     * 
     * @param string $iso2  ISO 3166-1 alpha-2 code
     */
    public function getCountry($iso2)
    {
        $iso2 = strtoupper($iso2);
        if (isset($countries[$iso2])) {
            return $countries[$iso2];
        }
        return null;
    }
    
    /**
     * 
     * @return string[]
     */
    public function getCountryNames()
    {
        if ($this->namesCache) {
            return $this->namesCache;
        }
        $return = array();
        foreach ($this->countries as $iso2 => $country) {
            $return[$iso2] = $country->name->common;
        }
        $this->namesCache = $return;
        return $return;
    }
    
    public function getTranslatedCountryNames($language, $commonOrOfficial = 'common')
    {
        $langMap = array(
            'en' => 'eng',
            'de' => 'deu',
            'es' => 'spa',
            'fr' => 'fra',
            'pt' => 'por',
            'it' => 'ita',
        );
        if (!isset($langMap[$language])) {
            throw \Exception('Language not found');
        }
        $lang = $langMap[$language];
        $return = array();
        foreach ($this->countries as $iso2 => $country) {
            if ($lang == 'eng') {
                $return[$iso2] = $country->name->$commonOrOfficial;
            } else {
                if ( property_exists($country->translations, $lang) && 
                    property_exists($country->translations->$lang, $commonOrOfficial)) {
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
     * @return string[][]
     */
    public function getCountryNameTranslations()
    {
        $return = array();
        foreach ($this->countries as $country) {
            $return[$country->name->common] = array(
                'en_US' => $country->name->common,
                'de_DE' => $country->translations->deu->common,
                'pt_BR' => $country->translations->por->common,
                'es_ES' => $country->translations->spa->common,
                'fr_FR' => $country->translations->fra->common,
            );
            if ($country->name->common != $country->name->official) {
                $return[$country->name->official] = array(
                    'en_US' => $country->name->official,
                    'de_DE' => $country->translations->deu->official,
                    'pt_BR' => $country->translations->por->official,
                    'es_ES' => $country->translations->spa->official,
                    'fr_FR' => $country->translations->fra->official,
                );
            }
        }
        return $return;
    }
}