<?php

namespace JTranslate\Model;

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

    /**
     * The locales getCountryNameTranslations() answers for.
     *
     * @var string[] $locales
     */
    protected $locales;

    /**
     * ISO 639-1 language subtag => the ISO 639-3 key `countries.json` uses.
     *
     * Exactly the thirteen languages the vendored file carries, and no more — verified
     * against the data rather than copied from a standard, because a key that is not in
     * the file would silently fall back to English while looking supported.
     *
     * Static on purpose. This is a fact about ISO codes and about the vendored data, not
     * about any installation, and nothing in ext-intl maps 639-1 to 639-3. What is *not*
     * static any more is the locale list — see getCountryNameTranslations().
     */
    private const LANGUAGE_KEYS = [
        'cy' => 'cym',
        'de' => 'deu',
        'fi' => 'fin',
        'fr' => 'fra',
        'hr' => 'hrv',
        'it' => 'ita',
        'ja' => 'jpn',
        'nl' => 'nld',
        'pt' => 'por',
        'ru' => 'rus',
        'es' => 'spa',
        'sk' => 'svk',
        'zh' => 'zho',
    ];

    public const COUNTRY_NAME_COMMON = 'common';
    public const COUNTRY_NAME_OFFICIAL = 'official';

    /**
     * @param array<\stdClass> $countries
     * @param string[] $locales every locale the installation wants country names in,
     *        including its key locale. Defaults to `['en_US']` rather than to the old
     *        hardcoded five, so a caller that has not been updated gets English — which
     *        is correct, if minimal — instead of translations for languages it never
     *        configured.
     */
    public function __construct($countries, array $locales = ['en_US'])
    {
        $this->locales = [] === $locales ? ['en_US'] : $locales;
        //key array
        $return = [];
        foreach ($countries as $obj) {
            $return[$obj->cca2] = $obj;
        }
        //add scotland manually
        $scotland = unserialize(serialize($return['GB']));
        $scotland->name->common = 'Scotland';
        $scotland->name->official = 'Scotland';
        $scotland->name->native->eng->official = 'Scotland';
        $scotland->name->native->eng->common = 'Scotland';
        $scotland->tld[0] = '.scot';
        $scotland->cca2 = 'GB-SCT';
        $scotland->ccn3 = 'GB-SCT';
        $scotland->cca3 = '';
        $scotland->cioc = '';
        $scotland->capital = 'Edinburgh';
        //Scotland is a clone of GB, so every translation it does not override is the
        //United Kingdom's name — in Italian it read "Regno Unito", in Dutch "Verenigd
        //Koninkrijk". That was invisible while getCountryNameTranslations() hardcoded the
        //four languages overridden below; it surfaced the moment the locale list became
        //configurable and `it_IT` started being answered.
        //
        //So the inherited names are cleared first and the known ones written back. A
        //language with no known name gets "Scotland", which is wrong-but-honest in the
        //same way every other missing translation is, rather than confidently naming a
        //different country.
        foreach (get_object_vars($scotland->translations) as $languageKey => $names) {
            $names->official = 'Scotland';
            $names->common   = 'Scotland';
        }
        $scotland->translations->deu->official = 'Schottland';
        $scotland->translations->deu->common = 'Schottland';
        $scotland->translations->fra->official = 'Écosse';
        $scotland->translations->fra->common = 'Écosse';
        $scotland->translations->spa->official = 'Escocia';
        $scotland->translations->spa->common = 'Escocia';
        $scotland->translations->por->official = 'Escócia';
        $scotland->translations->por->common = 'Escócia';
        $scotland->translations->ita->official = 'Scozia';
        $scotland->translations->ita->common = 'Scozia';
        $scotland->demonym = 'Scottish';
        $scotland->area = '77933';
        $return['GB-SCT'] = $scotland;
        $this->countries = $return;
    }

    /**
     * Nullable by design: both callers pass a nullable `Country` column
     * straight from the database and already guard the result with isset(),
     * so null in means null out. Declaring that stops `strtoupper(null)` from
     * raising a deprecation on every such row.
     *
     * @param string|null $iso2 ISO 3166-1 alpha-2 code
     */
    public function getCountry(?string $iso2)
    {
        if (null === $iso2) {
            return null;
        }
        $iso2 = strtoupper($iso2);
        if (isset($this->countries[$iso2])) {
            return $this->countries[$iso2];
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
        $return = [];
        foreach ($this->countries as $iso2 => $country) {
            $return[$iso2] = $country->name->common;
        }
        $this->namesCache = $return;
        return $return;
    }

    public function getTranslatedCountryNames($language, $commonOrOfficial = 'common')
    {
        $langMap = [
            'en' => 'eng',
            'de' => 'deu',
            'es' => 'spa',
            'fr' => 'fra',
            'pt' => 'por',
            'it' => 'ita',
        ];
        if (! isset($langMap[$language])) {
            throw new \Exception('Language not found');
        }
        $lang = $langMap[$language];
        $return = [];
        foreach ($this->countries as $iso2 => $country) {
            if ($lang == 'eng') {
                $return[$iso2] = $country->name->$commonOrOfficial;
            } else {
                if (
                    property_exists($country->translations, $lang) &&
                    property_exists($country->translations->$lang, $commonOrOfficial)
                ) {
                    $return[$iso2] = $country->translations->$lang->$commonOrOfficial;
                } else {
                //if we don't have the requested language, just return english
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
     * ## Which locales appear, and why the language table is still static
     *
     * The locales come from the installation's own configuration, passed in at
     * construction. They used to be a hardcoded `en_US`/`de_DE`/`pt_BR`/`es_ES`/`fr_FR`,
     * which was wrong in both directions at once here: `it_IT` is configured on
     * schoenstatt.link and got no country names at all, while `fr_FR` is not configured
     * anywhere and was computed on every call.
     *
     * What stays static is {@see LANGUAGE_KEYS}, and that is not the same kind of
     * hardcoding. It maps an ISO 639-1 language subtag to the ISO 639-3 key the vendored
     * `countries.json` uses, and it lists exactly the thirteen languages that file
     * actually carries — `cym deu fin fra hrv ita jpn nld por rus spa svk zho`. It is a
     * fact about the data, not about this installation, and there is no way to derive it:
     * `Locale::getPrimaryLanguage()` yields the 639-1 subtag and nothing in ext-intl maps
     * that to 639-3.
     *
     * A configured locale whose language is not in the file — or in the table — falls
     * back to the English name, which is what every missing translation already did.
     *
     * @return string[][]
     */
    public function getCountryNameTranslations()
    {
        //Resolved once rather than per country: 250 countries times the locale count.
        $keyed = [];
        foreach ($this->locales as $locale) {
            $language = \Locale::getPrimaryLanguage((string) $locale);
            //null rather than skipping, so a locale with no data still gets a key with
            //the English fallback below. A caller reading $translations[$name][$locale]
            //should never have to test whether the key exists.
            $keyed[(string) $locale] = self::LANGUAGE_KEYS[$language] ?? null;
        }

        $return = [];
        foreach ($this->countries as $country) {
            foreach (['common', 'official'] as $form) {
                $english = $country->name->$form;
                //The official name is only listed when it differs; an entry keyed by the
                //same string would just be overwritten.
                if ('official' === $form && $country->name->common == $english) {
                    continue;
                }
                $names = [];
                foreach ($keyed as $locale => $key) {
                    $names[$locale] = null !== $key && property_exists($country->translations, $key)
                        ? $country->translations->$key->$form
                        : $english;
                }
                $return[$english] = $names;
            }
        }
        return $return;
    }
}
