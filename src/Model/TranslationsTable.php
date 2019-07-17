<?php
namespace JTranslate\Model;

use Zend\Db\Adapter\AdapterAwareInterface;
use Zend\Db\TableGateway\AbstractTableGateway;
use Zend\Db\Adapter\Adapter;
use Zend\Db\TableGateway\TableGatewayInterface;
use Zend\Cache\Storage\StorageInterface;
use Zend\Db\Adapter\AdapterInterface;
use Zend\Db\Sql\Sql;
use Zend\Db\Sql\Where;
use ZfcUser\Entity\UserInterface;
use JUser\Model\UserTable;
use Zend\Code\Generator\ValueGenerator;
use Zend\Code\Generator\FileGenerator;
use Zend\Db\ResultSet\ResultSet;
use SionModel\Db\Model\SionCacheTrait;
use Zend\Mvc\MvcEvent;

class TranslationsTable extends AbstractTableGateway implements AdapterAwareInterface
{
    use SionCacheTrait;
    
    /**
     *
     * @var array $phrasesInDb
     */
    protected $phrasesInDb;
    /**
     *
     * @var array $newMissingTranslations
     */
    protected $newMissingPhrases;

    /**
     *
     * @var AdapterInterface $adapter
     */
    protected $adapter;

    /**
     *
     * @var TableGatewayInterface $phrasesGateway
     */
    protected $phrasesGateway;

    /**
     *
     * @var TableGatewayInterface $translationsGateway
     */
    protected $translationsGateway;

    /**
     *
     * @var \Traversable $config
     */
    protected $config;

    /**
     *
     * @var UserInterface $actingUser
     */
    protected $actingUser;

    /**
     *
     * @var UserTable $userTable
     */
    protected $userTable;

    /**
     *
     * @var array $arrayFilePatterns
     */
    protected $arrayFilePatterns;

    /**
     *
     * @var array $userModules
     */
    protected $userModules;

    /**
     * The full path to the root of the MVC project
     * @var string $rootDirectory
     */
    protected $rootDirectory;

    protected $filePattern = '%s.lang.php'; //@todo make this configurable

    /**
     *
     * @param TableGatewayInterface $gateway
     * @param StorageInterface $cache
     * @param array $config
     * @todo throw error if no project_name config key exists
     */
    public function __construct($phrasesGateway, $translationsGateway, $cache, $config, $actingUser, $userTable, $rootDirectory, $eventManager)
    {
        $this->phrasesGateway       = $phrasesGateway;
        $this->translationsGateway  = $translationsGateway;
        $this->adapter              = $phrasesGateway->getAdapter();
        $this->config               = $config;
        $this->actingUser           = $actingUser;
        $this->userTable            = $userTable;
        $this->newMissingPhrases    = [];
        
        if (isset($cache)) {
            $this->setPersistentCache($cache);
            $this->wireOnFinishTrigger($eventManager);
        }
        $eventManager->attach(MvcEvent::EVENT_FINISH, [$this, 'finishUp'], -1);
        
        $this->phrasesInDb          = $this->getPhraseKeysFromDb();
        $this->setRootDirectory($rootDirectory);
    }

    /**
     *  Set db adapter
     *
     *  @param Adapter $adapter
     *  @return self
     */
    public function setDbAdapter(Adapter $adapter)
    {
         $this->adapter = $adapter;
         $this->initialize();
         return $this;
    }

    /**
     *
     * @return array
     */
    public function getTranslations($fromAllProjects = false)
    {
//         $cacheKey = 'translations';
//         if ($fromAllProjects) {
//             $cacheKey.='-from-all-projects';
//         }
//         if (null !== ($cache = $this->fetchCachedEntityObjects($cacheKey))) {
//             return $cache;
//         }
        $sql = "SELECT t.`translation_id`,p.`translation_phrase_id`, t.`locale`,t.`translation`,
t.`modified_by`,t.`modified_on`, p.`text_domain`,  p.`phrase`, p.`added_on`, p.`project`, p.`origin_route`
FROM `trans_phrases` p
LEFT JOIN `trans_translations` t ON p.`translation_phrase_id` = t.`translation_phrase_id`
ORDER BY `text_domain`, `phrase`";
        $sqlParams = [$this->config['project_name']];
        $results = $this->fetchSome(null, $sql, $sqlParams);

        $utc = new \DateTimeZone('UTC');
        $userTable = $this->getUserTable();
        $users = $userTable->getUsers();
        $return = [];
        foreach ($results as $row) { //@todo avoid setting null locale keys for records without any translations
            //skip rows from other projects if we don't need them
            if (!$fromAllProjects && $row['project'] !== $this->config['project_name']) {
                continue;
            }
            //if we already already have an entry for this phrase
            $userId = (int)$row['modified_by'];
            $user = (isset($userId) && isset($users[$userId]))
                ? $users[$userId] : null;
            if (isset($return[$row['translation_phrase_id']])) {
                $return[$row['translation_phrase_id']][$row['locale']] = $row['translation'];
                $return[$row['translation_phrase_id']][$row['locale'].'Id'] = $row['translation_id'];
                $return[$row['translation_phrase_id']][$row['locale'].'ModifiedBy'] = $user;
                $return[$row['translation_phrase_id']][$row['locale'].'ModifiedOn'] = isset($row['modified_on']) ?
                    \DateTime::createFromFormat('Y-m-d H:i:s', $row['modified_on'], $utc) : null;
            } else {
                $return[$row['translation_phrase_id']] = [
                    'phraseId' => $row['translation_phrase_id'],
                    $row['locale']             => $row['translation'],
                    $row['locale'].'Id'        => $row['translation_id'],
                    $row['locale'].'ModifiedBy'=> $user,
                    $row['locale'].'ModifiedOn'=> $row['modified_on'] ?
                        \DateTime::createFromFormat('Y-m-d H:i:s', $row['modified_on'], $utc) : null,
                    'textDomain'                => $row['text_domain'],
                    'phrase'                    => $row['phrase'],
                    'originRoute'               => $row['origin_route'],
                    'addedOn'                   => $row['added_on'],
                ];
            }
        }
//         $this->cacheEntityObjects($cacheKey, $return, ['phrase']);
        return $return;
    }

    public function getOutstandingTranslationCount()
    {
        $sql = "SELECT p.`translation_phrase_id`, COUNT(*) AS PhraseLocaleCount
FROM `trans_phrases` p
LEFT JOIN `trans_translations` t ON p.`translation_phrase_id` = t.`translation_phrase_id`
WHERE (`project` = ?)
GROUP BY translation_phrase_id
HAVING PhraseLocaleCount < ?";
        $sqlParams = [$this->config['project_name'], count($this->config['locales_to_translate'])+1];
        $results = $this->fetchSome(null, $sql, $sqlParams);
        if (!$results) {
            return 0;
        } else {
            return count($results);
        }
    }

    public function getPhrase($id)
    {
        return $this->getTranslations()[$id];
    }

    public function updatePhrase($id, $data)
    {
        $phrase = $this->getTranslations()[$id];
        $dateString = date_format((new \DateTime(null, new \DateTimeZone('UTC'))), 'Y-m-d H:i:s');

        $locales = array_keys($this->getLocales(true));
        $results = [];
        foreach ($locales as $key) {
            if (!isset($data[$key]) || !$data[$key] ||
                (isset($phrase[$key]) && $data[$key] === $phrase[$key])) { //in the case that they didn't write anything, continue
                continue;
            }
            if (isset($data[$key.'Id']) && $data[$key.'Id'] && $phrase[$key.'Id']) { //if we have a translation id for the locale
                //update don't insert
                $sql = new Sql($this->adapter);
                $update = $sql->update($this->config['translations_table_name'])
                    ->set([
                        'translation' => $data[$key],
                        'modified_on' => $dateString,
                        'modified_by' => isset($this->actingUser) ? $this->actingUser->id : null,
                    ])
                    ->where(['translation_id' => $data[$key.'Id']]);
                $statement = $sql->prepareStatementForSqlObject($update);
                $results[] = $statement->execute();
            } else {
                //insert then
                $sql = new Sql($this->adapter);
                $insert = $sql->insert($this->config['translations_table_name'])
                ->values([
                    'translation_phrase_id' => $data['phraseId'],
                    'locale' => $key,
                    'translation' => $data[$key],
                    'modified_on' => $dateString,
                    'modified_by' => isset($this->actingUser) ? $this->actingUser->id : null,
                ]);
                $statement = $sql->prepareStatementForSqlObject($insert);
                $results[] = $statement->execute();
            }
        }
        $this->removeDependentCacheItems('phrase');
        return $results;
    }
    
    /**
     * Check if an entity exists
     * @param string $entity
     * @param number|string $id
     * @throws \Exception
     * @return boolean
     */
    public function existsPhrase($id)
    {
        $tableKey   = 'translation_phrase_id';
        $gateway    = $this->phrasesGateway;
        $result     = $gateway->select([$tableKey => $id]);
        if (!$result instanceof ResultSet || 0 === $result->count()) {
            return false;
        }
        if ($result->count() > 1) {
            throw new \Exception('Something weird. Multiple records returned.');
        }
        return true;
    }
    
    
    public function deletePhrase($id, $refreshCache = true)
    {
        //make sure entity exists before attempting to delete
        if (!$this->existsPhrase($id)) {
            throw new \Exception('The requested phrase for deletion '.$id.' does not exist.');
        }
        $gateway = $this->translationsGateway;
        $return = $gateway->delete(['translation_phrase_id' => $id]);
        
        $gateway = $this->phrasesGateway;
        $return = $gateway->delete(['translation_phrase_id' => $id]);
        
        if ($return !== 1) {
            throw new \Exception('Delete action expected a return code of \'1\', received \''.$return.'\'');
        }
        
        if ($refreshCache) {
            $this->removeDependentCacheItems('phrase');
        }
        
        return $return;
    }

    /**
     *
     * @return string[]
     */
    public function getLocales($shouldIncludeKeyLocale = false)
    {
        $return = [];
        $localeNames = $this->getLocaleNames();
        $locales = $this->config['locales_to_translate'];
        if ($shouldIncludeKeyLocale && $this->config && isset($this->config['key_locale']) &&
            !in_array($this->config['key_locale'], $locales)) {
            array_push($locales, $this->config['key_locale']);
        }
        foreach ($locales as $locale) {
            if (key_exists($locale, $localeNames)) {
                $return[$locale] = $localeNames[$locale];
            }
        }
        return $return;
    }

    /**
     *
     * @return string[][]
     */
    public function getPhraseKeysFromDb()
    {
        $cacheKey = 'phrase-keys';
        if (null !== ($cache = $this->fetchCachedEntityObjects($cacheKey))) {
            return $cache;
        }
        $where = new Sql($this->adapter);
        $where  ->select($this->config['phrases_table_name'])
                ->columns([
                    'translation_phrase_id',
                    'project',
                    'text_domain',
                    'phrase',
                    'added_on',
                ])
                ->where(['project' => $this->config['project_name']])
                ->order(['project', 'text_domain', 'phrase']);
        $results = $this->fetchSome($where);
        $return = [];
        foreach ($results as $tran) {
            if (key_exists($tran['text_domain'], $return)) {
                array_push($return[$tran['text_domain']], $tran['phrase']);
            } else {
                $return[$tran['text_domain']] = [
                    $tran['phrase']
                ];
            }
        }
        $this->cacheEntityObjects($cacheKey, $return, ['phrase']);
        return $return;
    }

    /**
     * Add to the list of translations to add to the database
     * @param array $params
     */
    protected function addMissingPhrase($params)
    {
        if (!isset($this->phrasesInDb[$params['text_domain']])) {
            $this->phrasesInDb[$params['text_domain']] = [$params['message']];
        } else {
            $this->phrasesInDb[$params['text_domain']][] = $params['message'];
        }
        if (!isset($this->newMissingPhrases[$params['text_domain']])) {
            $this->newMissingPhrases[$params['text_domain']] = [$params['message']];
        } else {
            $this->newMissingPhrases[$params['text_domain']][] = $params['message'];
        }
        return $this;
    }

    /**
     *
     * @param array $params
     */
    public function reportMissingTranslation($params)
    {
        if (!isset($this->phrasesInDb[$params['text_domain']]) ||
            !in_array($params['message'], $this->phrasesInDb[$params['text_domain']])) {
            $this->addMissingPhrase($params);
        }
        return $this;
    }

    /**
     * Returns the translated text of the db in a 4-dimensional array
     * @return string[][][]
     */
    public function getTranslatedText()
    {
        $cacheKey = 'translated-text';
        if (null !== ($cache = $this->fetchCachedEntityObjects($cacheKey))) {
            return $cache;
        }
        $sql = "SELECT t.`translation_id`,p.`translation_phrase_id`,
t.`locale`, t.`translation`, p.`text_domain`,  p.`phrase`
FROM `trans_phrases` p
INNER JOIN `trans_translations` t ON p.`translation_phrase_id` = t.`translation_phrase_id`
WHERE (p.`project` = ?)
ORDER BY `locale`, `text_domain`, `phrase`";
        $sqlParams = [$this->config['project_name']];
        $results = $this->fetchSome(null, $sql, $sqlParams);

        $return = [];
        foreach ($results as $tran) {
            if (isset($return[$tran['text_domain']])) {
                if (isset($return[$tran['text_domain']][$tran['locale']])) {
                    $return[$tran['text_domain']][$tran['locale']][$tran['phrase']] = $tran['translation'];
                } else {
                    $return[$tran['text_domain']][$tran['locale']] = [
                        $tran['phrase'] => $tran['translation']
                    ];
                }
            } else {
                $return[$tran['text_domain']] = [
                    $tran['locale'] => [
                        $tran['phrase'] => $tran['translation']
                    ]
                ];
            }
        }
        $this->cacheEntityObjects($cacheKey, $return, ['phrase']);
        return $return;
    }
    
    public function finishUp(MvcEvent $e)
    {
        $match = $e->getRouteMatch();
        $this->writeMissingPhrasesToDb($match ? $match->getMatchedRouteName() : null);
    }

    /**
     * Queries the database for the latest translations and rewrites all the files.
     *
     */
    public function writePhpTranslationArrays()
    {
        $translations = $this->getTranslatedText();
        foreach ($translations as $textDomain => $localeTrans) {
            foreach ($localeTrans as $locale => $trans) {
                //create an array value generator to write the file
                $generator = new ValueGenerator($trans, 'array');
                $file = FileGenerator::fromArray([
                    'body' => 'return '.$generator->generate().';',
                ]);
                $code = $file->generate();

                //if the current text domain is a module, then save it there. If not, to the root.
                if (key_exists($textDomain, $this->userModules)) {
                    $folder = $this->rootDirectory.'/module/'.$textDomain.'/language';
                    if (!file_exists($this->rootDirectory.'/module/'.$textDomain)) {
                        mkdir($this->rootDirectory.'/module/'.$textDomain, 0775, true);
                        @chmod($this->rootDirectory.'/module/'.$textDomain, 0775);
                    }
                } else {
                    $folder = $this->rootDirectory.'/language/'.$textDomain;
                    if (!file_exists($this->rootDirectory.'/language')) {
                        mkdir($this->rootDirectory.'/language', 0775, true);
                        @chmod($this->rootDirectory.'/language', 0775);
                    }
                }
                if (!is_dir($folder)) {
                    mkdir($folder, 0775, true);
                    @chmod($folder, 0775); /** @see https://stackoverflow.com/questions/3764973/php-mkdir-chmod-and-permissions#3769014 */
                }
                $fileToWrite = $folder.'/'.sprintf($this->filePattern, $locale);
                file_put_contents($fileToWrite, $code);
                @chmod($fileToWrite, 0775);
            }
        }
    }

    /**
     * Check the TranslationTable object for new missing translations and write them to the database to be translated
     * @param string $routeName
     * @return \Zend\Db\Adapter\Driver\ResultInterface[]
     */
    public function writeMissingPhrasesToDb($routeName = null)
    {
        $dateString = date_format((new \DateTime(null, new \DateTimeZone('UTC'))), 'Y-m-d H:i:s');
        $translations = $this->getTranslations(true);

        $localesToSearch = $this->config['locales_to_translate'];
        if (!in_array($this->config['key_locale'], $localesToSearch)) {
            $localesToSearch[] = $this->config['key_locale'];
        }

        //if we find something, we'll have to write the php arrays
        $weFoundAPreviousMatch = false;
        $result = [];
        foreach ($this->newMissingPhrases as $textDomain => $phrases) {
            foreach ($phrases as $phrase) {
                if (!isset($phrase)) {
                    continue;
                }
                //insert into phrases table
                $sql = new Sql($this->adapter);
                $insert =
                $sql->insert($this->config['phrases_table_name'])
                    ->values([
                        'project' => $this->config['project_name'],
                        'text_domain' => $textDomain,
                        'phrase' => $phrase,
                        'added_on' => $dateString,
                        'origin_route' => $routeName,
                    ]);
                $statement = $sql->prepareStatementForSqlObject($insert);
                $lastResult = $statement->execute();
                $phrasesKeyId = $lastResult->getGeneratedValue();
                $result[] = $lastResult;

                //see if we have a matching phrase in another text domain
                $translationsToInsert = [];
                foreach ($translations as $translationPhrase) {
                    if (0 === strcmp($translationPhrase['phrase'], $phrase)) { //we found a matching existing phrase
                        foreach ($localesToSearch as $locale) {
                            if (isset($translationPhrase[$locale]) && 0 !== strlen($translationPhrase[$locale])) { //we found a phrase-locale match
                                if (!isset($translationsToInsert[$locale])) {//we just take the first translation we find, no way to judge better and worse...
                                    $weFoundAPreviousMatch = true;
                                    $translationsToInsert[$locale] = [
                                        'translation_phrase_id' => $phrasesKeyId,
                                        'locale' => $locale,
                                        'translation' => $translationPhrase[$locale],
                                        'modified_by' => (isset($translationPhrase[$locale.'ModifiedBy']) &&
                                            isset($translationPhrase[$locale.'ModifiedBy']['userId'])) ?
                                            $translationPhrase[$locale.'ModifiedBy']['userId'] :
                                            (isset($this->actingUser) ? $this->actingUser->id : null),
                                        'modified_on' => $dateString,
                                    ];
                                }
                            }
                        }
                        if (count($translationsToInsert) === count($localesToSearch) // we have all the translations we need
                        ) {
                            continue;
                        }
                    }
                }

                //auto insert into translations table for the key locale
                if (!isset($translationsToInsert[$this->config['key_locale']])) {
                    $translationsToInsert[$this->config['key_locale']] = [
                        'translation_phrase_id' => $phrasesKeyId,
                        'locale' => $this->config['key_locale'],
                        'translation' => $phrase,
                        'modified_by' => isset($this->actingUser) ? $this->actingUser->id : null,
                        'modified_on' => $dateString,
                    ];
                }

                //insert rows
                foreach ($translationsToInsert as $row) {
                    $sql = new Sql($this->adapter);
                    $insert =
                    $sql->insert($this->config['translations_table_name'])
                        ->values($row);
                    $statement = $sql->prepareStatementForSqlObject($insert);
                    $lastResult = $statement->execute();
                    $result[] = $lastResult;
                }

            }
        }
        $this->removeDependentCacheItems('phrase');
        if ($weFoundAPreviousMatch) {
            $this->writePhpTranslationArrays();
        }
        return $result;
    }

    public function setUserModules($userModules)
    {
        $this->userModules = $userModules;
        return $this;
    }

    public function getUserTable()
    {
        if (!$this->userTable) {
            throw \Exception('User table not loaded into TranslationsTable');
        }
        return $this->userTable;
    }

    /**
     * @return string
     */
    public function getRootDirectory()
    {
        return $this->rootDirectory;
    }

    /**
     *
     * @param string $rootDirectory
     * @return self
     */
    public function setRootDirectory($rootDirectory)
    {
        $rootDirectory = rtrim($rootDirectory, '/');
        $rootDirectory = rtrim($rootDirectory, '\\');
        $this->rootDirectory = $rootDirectory;
        return $this;
    }

    /**
     * @param Where|\Closure|string|array $where
     * @param string
     * @param array
     * @return array
     */
    public function fetchSome($where, $sql = null, $sqlArgs = null, $gateway = null)
    {
        if (!isset($gateway)) {
            $gateway = $this->phrasesGateway;
        }
        if (!isset($where) && !isset($sql)) {
            throw \InvalidArgumentException('No query requested.');
        }
        if (isset($sql))
        {
	        if (!isset($sqlArgs)) {
	            $sqlArgs = Adapter::QUERY_MODE_EXECUTE; //make sure query executes
	        }
            $result = $this->adapter->query($sql, $sqlArgs);
        } else {
            $result = $gateway->select($where);
        }

        $return = [];
        foreach ($result as $row) {
            $return[] = $row;
        }
        return $return;
    }

    public function getLocaleNames()
    {
        return [
'af_NA' => 'Afrikaans (Namibia)',
'af_ZA' => 'Afrikaans (South Africa)',
'af' => 'Afrikaans',
'ak_GH' => 'Akan (Ghana)',
'ak' => 'Akan',
'sq_AL' => 'Albanian (Albania)',
'sq' => 'Albanian',
'am_ET' => 'Amharic (Ethiopia)',
'am' => 'Amharic',
'ar_DZ' => 'Arabic (Algeria)',
'ar_BH' => 'Arabic (Bahrain)',
'ar_EG' => 'Arabic (Egypt)',
'ar_IQ' => 'Arabic (Iraq)',
'ar_JO' => 'Arabic (Jordan)',
'ar_KW' => 'Arabic (Kuwait)',
'ar_LB' => 'Arabic (Lebanon)',
'ar_LY' => 'Arabic (Libya)',
'ar_MA' => 'Arabic (Morocco)',
'ar_OM' => 'Arabic (Oman)',
'ar_QA' => 'Arabic (Qatar)',
'ar_SA' => 'Arabic (Saudi Arabia)',
'ar_SD' => 'Arabic (Sudan)',
'ar_SY' => 'Arabic (Syria)',
'ar_TN' => 'Arabic (Tunisia)',
'ar_AE' => 'Arabic (United Arab Emirates)',
'ar_YE' => 'Arabic (Yemen)',
'ar' => 'Arabic',
'hy_AM' => 'Armenian (Armenia)',
'hy' => 'Armenian',
'as_IN' => 'Assamese (India)',
'as' => 'Assamese',
'asa_TZ' => 'Asu (Tanzania)',
'asa' => 'Asu',
'az_Cyrl' => 'Azerbaijani (Cyrillic)',
'az_Cyrl_AZ' => 'Azerbaijani (Cyrillic, Azerbaijan)',
'az_Latn' => 'Azerbaijani (Latin)',
'az_Latn_AZ' => 'Azerbaijani (Latin, Azerbaijan)',
'az' => 'Azerbaijani',
'bm_ML' => 'Bambara (Mali)',
'bm' => 'Bambara',
'eu_ES' => 'Basque (Spain)',
'eu' => 'Basque',
'be_BY' => 'Belarusian (Belarus)',
'be' => 'Belarusian',
'bem_ZM' => 'Bemba (Zambia)',
'bem' => 'Bemba',
'bez_TZ' => 'Bena (Tanzania)',
'bez' => 'Bena',
'bn_BD' => 'Bengali (Bangladesh)',
'bn_IN' => 'Bengali (India)',
'bn' => 'Bengali',
'bs_BA' => 'Bosnian (Bosnia and Herzegovina)',
'bs' => 'Bosnian',
'bg_BG' => 'Bulgarian (Bulgaria)',
'bg' => 'Bulgarian',
'my_MM' => 'Burmese (Myanmar [Burma])',
'my' => 'Burmese',
'ca_ES' => 'Catalan (Spain)',
'ca' => 'Catalan',
'tzm_Latn' => 'Central Morocco Tamazight (Latin)',
'tzm_Latn_MA' => 'Central Morocco Tamazight (Latin, Morocco)',
'tzm' => 'Central Morocco Tamazight',
'chr_US' => 'Cherokee (United States)',
'chr' => 'Cherokee',
'cgg_UG' => 'Chiga (Uganda)',
'cgg' => 'Chiga',
'zh_Hans' => 'Chinese (Simplified Han)',
'zh_Hans_CN' => 'Chinese (Simplified Han, China)',
'zh_Hans_HK' => 'Chinese (Simplified Han, Hong Kong SAR China)',
'zh_Hans_MO' => 'Chinese (Simplified Han, Macau SAR China)',
'zh_Hans_SG' => 'Chinese (Simplified Han, Singapore)',
'zh_Hant' => 'Chinese (Traditional Han)',
'zh_Hant_HK' => 'Chinese (Traditional Han, Hong Kong SAR China)',
'zh_Hant_MO' => 'Chinese (Traditional Han, Macau SAR China)',
'zh_Hant_TW' => 'Chinese (Traditional Han, Taiwan)',
'zh' => 'Chinese',
'kw_GB' => 'Cornish (United Kingdom)',
'kw' => 'Cornish',
'hr_HR' => 'Croatian (Croatia)',
'hr' => 'Croatian',
'cs_CZ' => 'Czech (Czech Republic)',
'cs' => 'Czech',
'da_DK' => 'Danish (Denmark)',
'da' => 'Danish',
'nl_BE' => 'Dutch (Belgium)',
'nl_NL' => 'Dutch (Netherlands)',
'nl' => 'Dutch',
'ebu_KE' => 'Embu (Kenya)',
'ebu' => 'Embu',
'en_AS' => 'English (American Samoa)',
'en_AU' => 'English (Australia)',
'en_BE' => 'English (Belgium)',
'en_BZ' => 'English (Belize)',
'en_BW' => 'English (Botswana)',
'en_CA' => 'English (Canada)',
'en_GU' => 'English (Guam)',
'en_HK' => 'English (Hong Kong SAR China)',
'en_IN' => 'English (India)',
'en_IE' => 'English (Ireland)',
'en_JM' => 'English (Jamaica)',
'en_MT' => 'English (Malta)',
'en_MH' => 'English (Marshall Islands)',
'en_MU' => 'English (Mauritius)',
'en_NA' => 'English (Namibia)',
'en_NZ' => 'English (New Zealand)',
'en_MP' => 'English (Northern Mariana Islands)',
'en_PK' => 'English (Pakistan)',
'en_PH' => 'English (Philippines)',
'en_SG' => 'English (Singapore)',
'en_ZA' => 'English (South Africa)',
'en_TT' => 'English (Trinidad and Tobago)',
'en_UM' => 'English (U.S. Minor Outlying Islands)',
'en_VI' => 'English (U.S. Virgin Islands)',
'en_GB' => 'English (United Kingdom)',
'en_US' => 'English (United States)',
'en_ZW' => 'English (Zimbabwe)',
'en' => 'English',
'eo' => 'Esperanto',
'et_EE' => 'Estonian (Estonia)',
'et' => 'Estonian',
'ee_GH' => 'Ewe (Ghana)',
'ee_TG' => 'Ewe (Togo)',
'ee' => 'Ewe',
'fo_FO' => 'Faroese (Faroe Islands)',
'fo' => 'Faroese',
'fil_PH' => 'Filipino (Philippines)',
'fil' => 'Filipino',
'fi_FI' => 'Finnish (Finland)',
'fi' => 'Finnish',
'fr_BE' => 'French (Belgium)',
'fr_BJ' => 'French (Benin)',
'fr_BF' => 'French (Burkina Faso)',
'fr_BI' => 'French (Burundi)',
'fr_CM' => 'French (Cameroon)',
'fr_CA' => 'French (Canada)',
'fr_CF' => 'French (Central African Republic)',
'fr_TD' => 'French (Chad)',
'fr_KM' => 'French (Comoros)',
'fr_CG' => 'French (Congo - Brazzaville)',
'fr_CD' => 'French (Congo - Kinshasa)',
'fr_CI' => 'French (Côte d’Ivoire)',
'fr_DJ' => 'French (Djibouti)',
'fr_GQ' => 'French (Equatorial Guinea)',
'fr_FR' => 'French (France)',
'fr_GA' => 'French (Gabon)',
'fr_GP' => 'French (Guadeloupe)',
'fr_GN' => 'French (Guinea)',
'fr_LU' => 'French (Luxembourg)',
'fr_MG' => 'French (Madagascar)',
'fr_ML' => 'French (Mali)',
'fr_MQ' => 'French (Martinique)',
'fr_MC' => 'French (Monaco)',
'fr_NE' => 'French (Niger)',
'fr_RW' => 'French (Rwanda)',
'fr_RE' => 'French (Réunion)',
'fr_BL' => 'French (Saint Barthélemy)',
'fr_MF' => 'French (Saint Martin)',
'fr_SN' => 'French (Senegal)',
'fr_CH' => 'French (Switzerland)',
'fr_TG' => 'French (Togo)',
'fr' => 'French',
'ff_SN' => 'Fulah (Senegal)',
'ff' => 'Fulah',
'gl_ES' => 'Galician (Spain)',
'gl' => 'Galician',
'lg_UG' => 'Ganda (Uganda)',
'lg' => 'Ganda',
'ka_GE' => 'Georgian (Georgia)',
'ka' => 'Georgian',
'de_AT' => 'German (Austria)',
'de_BE' => 'German (Belgium)',
'de_DE' => 'German (Germany)',
'de_LI' => 'German (Liechtenstein)',
'de_LU' => 'German (Luxembourg)',
'de_CH' => 'German (Switzerland)',
'de' => 'German',
'el_CY' => 'Greek (Cyprus)',
'el_GR' => 'Greek (Greece)',
'el' => 'Greek',
'gu_IN' => 'Gujarati (India)',
'gu' => 'Gujarati',
'guz_KE' => 'Gusii (Kenya)',
'guz' => 'Gusii',
'ha_Latn' => 'Hausa (Latin)',
'ha_Latn_GH' => 'Hausa (Latin, Ghana)',
'ha_Latn_NE' => 'Hausa (Latin, Niger)',
'ha_Latn_NG' => 'Hausa (Latin, Nigeria)',
'ha' => 'Hausa',
'haw_US' => 'Hawaiian (United States)',
'haw' => 'Hawaiian',
'he_IL' => 'Hebrew (Israel)',
'he' => 'Hebrew',
'hi_IN' => 'Hindi (India)',
'hi' => 'Hindi',
'hu_HU' => 'Hungarian (Hungary)',
'hu' => 'Hungarian',
'is_IS' => 'Icelandic (Iceland)',
'is' => 'Icelandic',
'ig_NG' => 'Igbo (Nigeria)',
'ig' => 'Igbo',
'id_ID' => 'Indonesian (Indonesia)',
'id' => 'Indonesian',
'ga_IE' => 'Irish (Ireland)',
'ga' => 'Irish',
'it_IT' => 'Italian (Italy)',
'it_CH' => 'Italian (Switzerland)',
'it' => 'Italian',
'ja_JP' => 'Japanese (Japan)',
'ja' => 'Japanese',
'kea_CV' => 'Kabuverdianu (Cape Verde)',
'kea' => 'Kabuverdianu',
'kab_DZ' => 'Kabyle (Algeria)',
'kab' => 'Kabyle',
'kl_GL' => 'Kalaallisut (Greenland)',
'kl' => 'Kalaallisut',
'kln_KE' => 'Kalenjin (Kenya)',
'kln' => 'Kalenjin',
'kam_KE' => 'Kamba (Kenya)',
'kam' => 'Kamba',
'kn_IN' => 'Kannada (India)',
'kn' => 'Kannada',
'kk_Cyrl' => 'Kazakh (Cyrillic)',
'kk_Cyrl_KZ' => 'Kazakh (Cyrillic, Kazakhstan)',
'kk' => 'Kazakh',
'km_KH' => 'Khmer (Cambodia)',
'km' => 'Khmer',
'ki_KE' => 'Kikuyu (Kenya)',
'ki' => 'Kikuyu',
'rw_RW' => 'Kinyarwanda (Rwanda)',
'rw' => 'Kinyarwanda',
'kok_IN' => 'Konkani (India)',
'kok' => 'Konkani',
'ko_KR' => 'Korean (South Korea)',
'ko' => 'Korean',
'khq_ML' => 'Koyra Chiini (Mali)',
'khq' => 'Koyra Chiini',
'ses_ML' => 'Koyraboro Senni (Mali)',
'ses' => 'Koyraboro Senni',
'lag_TZ' => 'Langi (Tanzania)',
'lag' => 'Langi',
'lv_LV' => 'Latvian (Latvia)',
'lv' => 'Latvian',
'lt_LT' => 'Lithuanian (Lithuania)',
'lt' => 'Lithuanian',
'luo_KE' => 'Luo (Kenya)',
'luo' => 'Luo',
'luy_KE' => 'Luyia (Kenya)',
'luy' => 'Luyia',
'mk_MK' => 'Macedonian (Macedonia)',
'mk' => 'Macedonian',
'jmc_TZ' => 'Machame (Tanzania)',
'jmc' => 'Machame',
'kde_TZ' => 'Makonde (Tanzania)',
'kde' => 'Makonde',
'mg_MG' => 'Malagasy (Madagascar)',
'mg' => 'Malagasy',
'ms_BN' => 'Malay (Brunei)',
'ms_MY' => 'Malay (Malaysia)',
'ms' => 'Malay',
'ml_IN' => 'Malayalam (India)',
'ml' => 'Malayalam',
'mt_MT' => 'Maltese (Malta)',
'mt' => 'Maltese',
'gv_GB' => 'Manx (United Kingdom)',
'gv' => 'Manx',
'mr_IN' => 'Marathi (India)',
'mr' => 'Marathi',
'mas_KE' => 'Masai (Kenya)',
'mas_TZ' => 'Masai (Tanzania)',
'mas' => 'Masai',
'mer_KE' => 'Meru (Kenya)',
'mer' => 'Meru',
'mfe_MU' => 'Morisyen (Mauritius)',
'mfe' => 'Morisyen',
'naq_NA' => 'Nama (Namibia)',
'naq' => 'Nama',
'ne_IN' => 'Nepali (India)',
'ne_NP' => 'Nepali (Nepal)',
'ne' => 'Nepali',
'nd_ZW' => 'North Ndebele (Zimbabwe)',
'nd' => 'North Ndebele',
'nb_NO' => 'Norwegian Bokmål (Norway)',
'nb' => 'Norwegian Bokmål',
'nn_NO' => 'Norwegian Nynorsk (Norway)',
'nn' => 'Norwegian Nynorsk',
'nyn_UG' => 'Nyankole (Uganda)',
'nyn' => 'Nyankole',
'or_IN' => 'Oriya (India)',
'or' => 'Oriya',
'om_ET' => 'Oromo (Ethiopia)',
'om_KE' => 'Oromo (Kenya)',
'om' => 'Oromo',
'ps_AF' => 'Pashto (Afghanistan)',
'ps' => 'Pashto',
'fa_AF' => 'Persian (Afghanistan)',
'fa_IR' => 'Persian (Iran)',
'fa' => 'Persian',
'pl_PL' => 'Polish (Poland)',
'pl' => 'Polish',
'pt_BR' => 'Portuguese (Brazil)',
'pt_GW' => 'Portuguese (Guinea-Bissau)',
'pt_MZ' => 'Portuguese (Mozambique)',
'pt_PT' => 'Portuguese (Portugal)',
'pt' => 'Portuguese',
'pa_Arab' => 'Punjabi (Arabic)',
'pa_Arab_PK' => 'Punjabi (Arabic, Pakistan)',
'pa_Guru' => 'Punjabi (Gurmukhi)',
'pa_Guru_IN' => 'Punjabi (Gurmukhi, India)',
'pa' => 'Punjabi',
'ro_MD' => 'Romanian (Moldova)',
'ro_RO' => 'Romanian (Romania)',
'ro' => 'Romanian',
'rm_CH' => 'Romansh (Switzerland)',
'rm' => 'Romansh',
'rof_TZ' => 'Rombo (Tanzania)',
'rof' => 'Rombo',
'ru_MD' => 'Russian (Moldova)',
'ru_RU' => 'Russian (Russia)',
'ru_UA' => 'Russian (Ukraine)',
'ru' => 'Russian',
'rwk_TZ' => 'Rwa (Tanzania)',
'rwk' => 'Rwa',
'saq_KE' => 'Samburu (Kenya)',
'saq' => 'Samburu',
'sg_CF' => 'Sango (Central African Republic)',
'sg' => 'Sango',
'seh_MZ' => 'Sena (Mozambique)',
'seh' => 'Sena',
'sr_Cyrl' => 'Serbian (Cyrillic)',
'sr_Cyrl_BA' => 'Serbian (Cyrillic, Bosnia and Herzegovina)',
'sr_Cyrl_ME' => 'Serbian (Cyrillic, Montenegro)',
'sr_Cyrl_RS' => 'Serbian (Cyrillic, Serbia)',
'sr_Latn' => 'Serbian (Latin)',
'sr_Latn_BA' => 'Serbian (Latin, Bosnia and Herzegovina)',
'sr_Latn_ME' => 'Serbian (Latin, Montenegro)',
'sr_Latn_RS' => 'Serbian (Latin, Serbia)',
'sr' => 'Serbian',
'sn_ZW' => 'Shona (Zimbabwe)',
'sn' => 'Shona',
'ii_CN' => 'Sichuan Yi (China)',
'ii' => 'Sichuan Yi',
'si_LK' => 'Sinhala (Sri Lanka)',
'si' => 'Sinhala',
'sk_SK' => 'Slovak (Slovakia)',
'sk' => 'Slovak',
'sl_SI' => 'Slovenian (Slovenia)',
'sl' => 'Slovenian',
'xog_UG' => 'Soga (Uganda)',
'xog' => 'Soga',
'so_DJ' => 'Somali (Djibouti)',
'so_ET' => 'Somali (Ethiopia)',
'so_KE' => 'Somali (Kenya)',
'so_SO' => 'Somali (Somalia)',
'so' => 'Somali',
'es_AR' => 'Spanish (Argentina)',
'es_BO' => 'Spanish (Bolivia)',
'es_CL' => 'Spanish (Chile)',
'es_CO' => 'Spanish (Colombia)',
'es_CR' => 'Spanish (Costa Rica)',
'es_DO' => 'Spanish (Dominican Republic)',
'es_EC' => 'Spanish (Ecuador)',
'es_SV' => 'Spanish (El Salvador)',
'es_GQ' => 'Spanish (Equatorial Guinea)',
'es_GT' => 'Spanish (Guatemala)',
'es_HN' => 'Spanish (Honduras)',
'es_419' => 'Spanish (Latin America)',
'es_MX' => 'Spanish (Mexico)',
'es_NI' => 'Spanish (Nicaragua)',
'es_PA' => 'Spanish (Panama)',
'es_PY' => 'Spanish (Paraguay)',
'es_PE' => 'Spanish (Peru)',
'es_PR' => 'Spanish (Puerto Rico)',
'es_ES' => 'Spanish (Spain)',
'es_US' => 'Spanish (United States)',
'es_UY' => 'Spanish (Uruguay)',
'es_VE' => 'Spanish (Venezuela)',
'es' => 'Spanish',
'sw_KE' => 'Swahili (Kenya)',
'sw_TZ' => 'Swahili (Tanzania)',
'sw' => 'Swahili',
'sv_FI' => 'Swedish (Finland)',
'sv_SE' => 'Swedish (Sweden)',
'sv' => 'Swedish',
'gsw_CH' => 'Swiss German (Switzerland)',
'gsw' => 'Swiss German',
'shi_Latn' => 'Tachelhit (Latin)',
'shi_Latn_MA' => 'Tachelhit (Latin, Morocco)',
'shi_Tfng' => 'Tachelhit (Tifinagh)',
'shi_Tfng_MA' => 'Tachelhit (Tifinagh, Morocco)',
'shi' => 'Tachelhit',
'dav_KE' => 'Taita (Kenya)',
'dav' => 'Taita',
'ta_IN' => 'Tamil (India)',
'ta_LK' => 'Tamil (Sri Lanka)',
'ta' => 'Tamil',
'te_IN' => 'Telugu (India)',
'te' => 'Telugu',
'teo_KE' => 'Teso (Kenya)',
'teo_UG' => 'Teso (Uganda)',
'teo' => 'Teso',
'th_TH' => 'Thai (Thailand)',
'th' => 'Thai',
'bo_CN' => 'Tibetan (China)',
'bo_IN' => 'Tibetan (India)',
'bo' => 'Tibetan',
'ti_ER' => 'Tigrinya (Eritrea)',
'ti_ET' => 'Tigrinya (Ethiopia)',
'ti' => 'Tigrinya',
'to_TO' => 'Tonga (Tonga)',
'to' => 'Tonga',
'tr_TR' => 'Turkish (Turkey)',
'tr' => 'Turkish',
'uk_UA' => 'Ukrainian (Ukraine)',
'uk' => 'Ukrainian',
'ur_IN' => 'Urdu (India)',
'ur_PK' => 'Urdu (Pakistan)',
'ur' => 'Urdu',
'uz_Arab' => 'Uzbek (Arabic)',
'uz_Arab_AF' => 'Uzbek (Arabic, Afghanistan)',
'uz_Cyrl' => 'Uzbek (Cyrillic)',
'uz_Cyrl_UZ' => 'Uzbek (Cyrillic, Uzbekistan)',
'uz_Latn' => 'Uzbek (Latin)',
'uz_Latn_UZ' => 'Uzbek (Latin, Uzbekistan)',
'uz' => 'Uzbek',
'vi_VN' => 'Vietnamese (Vietnam)',
'vi' => 'Vietnamese',
'vun_TZ' => 'Vunjo (Tanzania)',
'vun' => 'Vunjo',
'cy_GB' => 'Welsh (United Kingdom)',
'cy' => 'Welsh',
'yo_NG' => 'Yoruba (Nigeria)',
'yo' => 'Yoruba',
'zu_ZA' => 'Zulu (South Africa)',
'zu' => 'Zulu'
          ];
    }
}
