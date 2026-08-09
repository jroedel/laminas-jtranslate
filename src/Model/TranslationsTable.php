<?php

namespace JTranslate\Model;

use Laminas\Db\Adapter\AdapterAwareInterface;
use Laminas\Db\TableGateway\AbstractTableGateway;
use Laminas\Db\Adapter\Adapter;
use Laminas\Db\TableGateway\TableGatewayInterface;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Sql\Sql;
use Laminas\Db\Sql\Where;
use Laminas\Db\ResultSet\ResultSet;
use JTranslate\Cache\PhraseCache;
use JTranslate\Service\ActingUserProviderInterface;
use JTranslate\Service\UserDirectoryInterface;

class TranslationsTable extends AbstractTableGateway implements AdapterAwareInterface
{
    /**
     * Every phrase already known to the database, as [text domain => [md5 => true]].
     *
     * @see getPhraseIndex() for why it holds hashes rather than the phrases.
     * @var array<string, array<string, true>> $phraseIndex
     */
    protected $phraseIndex = [];

    /**
     * Whether $phraseIndex has been read from the database yet.
     *
     * A separate flag rather than a null check, because an empty index is a legitimate
     * answer — a brand new project has no phrases — and testing emptiness would make
     * every miss re-run the query.
     *
     * @var bool $phraseIndexLoaded
     */
    protected $phraseIndexLoaded = false;

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
     * @var ActingUserProviderInterface|null $actingUserProvider
     */
    protected $actingUserProvider;

    /**
     *
     * @var UserDirectoryInterface|null $userTable
     */
    protected $userTable;

    /**
     *
     * @var PhraseCache $cache
     */
    protected $cache;

    /**
     *
     * @var array $arrayFilePatterns
     */
    protected $arrayFilePatterns;

    /**
     *
     * @var array $userModules
     */
    protected $userModules = [];

    /**
     * The full path to the root of the MVC project
     * @var string $rootDirectory
     */
    protected $rootDirectory;

    protected $filePattern = '%s.lang.php'; //@todo make this configurable

    /**
     * There is no event manager parameter and no framework type in this signature.
     *
     * The old constructor took a Laminas\EventManager and attached itself to
     * MvcEvent::EVENT_FINISH from inside the model, which is why a translation model
     * could not be constructed at all without laminas-mvc — not in a Symfony request,
     * not in a console command, not in a unit test. Wiring the end-of-request flush is
     * the framework adapter's job and now lives in TranslationsTableFactory, which is
     * allowed to know it is running under laminas. Call flush() yourself from anywhere
     * else.
     *
     * The eager getPhraseIndex() call is gone too. It ran a query over every phrase in
     * the project before the router had matched, so a request that never translated
     * anything still paid for it. It is now read on first use.
     *
     * @param TableGatewayInterface $phrasesGateway
     * @param TableGatewayInterface $translationsGateway
     * @param PhraseCache $cache
     * @param array $config
     * @param ActingUserProviderInterface|null $actingUserProvider
     * @param UserDirectoryInterface|null $userTable
     * @param string $rootDirectory
     * @todo throw error if no project_name config key exists
     */
    public function __construct(
        $phrasesGateway,
        $translationsGateway,
        PhraseCache $cache,
        $config,
        $actingUserProvider,
        $userTable,
        $rootDirectory
    ) {
        $this->phrasesGateway       = $phrasesGateway;
        $this->translationsGateway  = $translationsGateway;
        $this->adapter              = $phrasesGateway->getAdapter();
        $this->config               = $config;
        $this->cache                = $cache;
        $this->actingUserProvider   = $actingUserProvider;
        $this->userTable            = $userTable;
        $this->newMissingPhrases    = [];

        $this->setRootDirectory($rootDirectory);
    }

    /**
     *
     * @return int|null
     */
    protected function getActingUserId(): ?int
    {
        if (null === $this->actingUserProvider) {
            return null;
        }
        return $this->actingUserProvider->getActingUserId();
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
        $sql = "SELECT t.`translation_id`,p.`translation_phrase_id`, t.`locale`,t.`translation`,
t.`modified_by`,t.`modified_on`, p.`text_domain`,  p.`phrase`, p.`added_on`, p.`project`, p.`origin_route`
FROM `trans_phrases` p
LEFT JOIN `trans_translations` t ON p.`translation_phrase_id` = t.`translation_phrase_id`
ORDER BY `text_domain`, `phrase`";
        $results = $this->fetchSome(null, $sql);

        $utc = new \DateTimeZone('UTC');
        $userTable = $this->getUserTable();
        $users = $userTable->getUsers();
        $return = [];
        foreach ($results as $row) {
            //skip rows from other projects if we don't need them
            if (! $fromAllProjects && $row['project'] !== $this->config['project_name']) {
                continue;
            }
            $phraseId = $row['translation_phrase_id'];

            //The phrase-level keys are the same on every row for a phrase, so they
            //are set once, independently of the locale columns below. They used to
            //be written only on the branch that created the entry, interleaved with
            //that row's locale keys; splitting them is what allows a phrase with no
            //translations at all to get an entry without a null-keyed locale.
            if (! isset($return[$phraseId])) {
                $return[$phraseId] = [
                    'phraseId'    => $phraseId,
                    'textDomain'  => $row['text_domain'],
                    'phrase'      => $row['phrase'],
                    'originRoute' => $row['origin_route'],
                    'addedOn'     => $row['added_on'],
                ];
            }

            //This is a LEFT JOIN, so a phrase with no translation rows arrives once
            //with every t.* column NULL. Writing $return[$phraseId][null] for it is
            //deprecated in PHP 8.5 and an Error in PHP 9 — and it ran on every
            //request through finishUp(), so it was a site-wide fatal in waiting, not
            //an admin-page one. Nothing downstream ever wanted the null-keyed entry:
            //readers ask for a specific locale by name.
            if (null === $row['locale']) {
                continue;
            }

            $locale = $row['locale'];
            $userId = (int) $row['modified_by'];
            $return[$phraseId][$locale]                = $row['translation'];
            $return[$phraseId][$locale . 'Id']         = $row['translation_id'];
            $return[$phraseId][$locale . 'ModifiedBy'] = $users[$userId] ?? null;
            $return[$phraseId][$locale . 'ModifiedOn'] = isset($row['modified_on'])
                ? \DateTime::createFromFormat('Y-m-d H:i:s', $row['modified_on'], $utc)
                : null;
        }
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
        $sqlParams = [$this->config['project_name'], count($this->config['locales_to_translate']) + 1];
        $results = $this->fetchSome(null, $sql, $sqlParams);
        if (! $results) {
            return 0;
        } else {
            return count($results);
        }
    }

    public function getPhrase($id)
    {
        return $this->getTranslations()[$id];
    }

    /**
     * Write the submitted translations for one phrase.
     *
     * Only $data[$locale] — the translated text itself — is taken from the
     * caller. Every identifier used to decide *which row* is written comes from
     * $phrase, i.e. from the database, keyed by the $id the caller already had
     * to be authorized for. That split is the whole security property of this
     * method, and it is not cosmetic: this code previously put
     * $data[$locale . 'Id'] straight into the UPDATE's WHERE clause, so a
     * translator editing any one phrase could rewrite the translation of any
     * other phrase in the table by editing a hidden field. The submitted
     * *Id fields are now read only as a hint that a row exists, never as the
     * row to write, and the same reasoning applies to translation_phrase_id on
     * the INSERT branch, which took $data['phraseId'] for a value the caller
     * was authorized for exactly once, in the controller, by loose comparison.
     *
     * @param int $id
     * @param array $data
     * @return array
     */
    public function updatePhrase($id, $data)
    {
        $phrase = $this->getTranslations()[$id];
        $dateString = date_format((new \DateTime('now', new \DateTimeZone('UTC'))), 'Y-m-d H:i:s');

        $locales = array_keys($this->getLocales(true));
        $results = [];
        foreach ($locales as $key) {
            if (
                ! isset($data[$key]) || ! $data[$key] ||
                (isset($phrase[$key]) && $data[$key] === $phrase[$key])
            ) { //in the case that they didn't write anything, continue
                continue;
            }
            if (isset($phrase[$key . 'Id']) && $phrase[$key . 'Id']) { //this locale already has a row for this phrase
                //update don't insert
                $sql = new Sql($this->adapter);
                $update = $sql->update($this->config['translations_table_name'])
                    ->set([
                        'translation' => $data[$key],
                        'modified_on' => $dateString,
                        'modified_by' => $this->getActingUserId(),
                    ])
                    ->where(['translation_id' => $phrase[$key . 'Id']]);
                $statement = $sql->prepareStatementForSqlObject($update);
                $results[] = $statement->execute();
            } else {
                //insert then
                $sql = new Sql($this->adapter);
                $insert = $sql->insert($this->config['translations_table_name'])
                ->values([
                    'translation_phrase_id' => $id,
                    'locale' => $key,
                    'translation' => $data[$key],
                    'modified_on' => $dateString,
                    'modified_by' => $this->getActingUserId(),
                ]);
                $statement = $sql->prepareStatementForSqlObject($insert);
                $results[] = $statement->execute();
            }
        }
        $this->invalidatePhraseCaches();
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
        if (! $result instanceof ResultSet || 0 === $result->count()) {
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
        if (! $this->existsPhrase($id)) {
            throw new \Exception('The requested phrase for deletion ' . $id . ' does not exist.');
        }
        $gateway = $this->translationsGateway;
        $return = $gateway->delete(['translation_phrase_id' => $id]);

        $gateway = $this->phrasesGateway;
        $return = $gateway->delete(['translation_phrase_id' => $id]);

        if ($return !== 1) {
            throw new \Exception('Delete action expected a return code of \'1\', received \'' . $return . '\'');
        }

        if ($refreshCache) {
            $this->invalidatePhraseCaches();
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
        if (
            $shouldIncludeKeyLocale && $this->config && isset($this->config['key_locale']) &&
            ! in_array($this->config['key_locale'], $locales)
        ) {
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
     * The set of phrases already in the database, per text domain, as hashes.
     *
     * ## Why hashes, and why this used to be uncacheable
     *
     * This is a membership set and nothing else — the only question ever asked of it
     * is "have we seen this phrase in this domain before". It used to hold the phrases
     * themselves, in a list, which made it wrong twice over.
     *
     * Measured on this project's data: 6,860 phrases whose average length is 1,494
     * characters, because 86% of them are book and dictionary bodies rather than UI
     * labels. Serialized, that array is **9.9 MiB**. It therefore blew straight past
     * the cache's item budget and was silently never stored, so the query behind it
     * ran on every single request — and had it been stored, an item that size against
     * production's 32 MB APCu segment is the exact shape of the allocation failure
     * that wipes the whole segment. Hashing takes it to roughly 270 KiB, which fits,
     * so this is cached for the first time.
     *
     * Keying by hash also turns the membership test from an in_array() scan over up to
     * 5,922 strings into an isset().
     *
     * ## Why the hashing is not done in SQL
     *
     * `SELECT MD5(phrase)` would move 220 KiB over the wire instead of 10 MiB, and it
     * is tempting. It is also charset-dependent: MySQL's MD5() hashes the value's bytes
     * in its own character set, and any mismatch with what PHP receives yields hashes
     * that never match. The failure would be silent and permanent — every phrase would
     * look new, so every request would insert duplicates of every phrase forever. The
     * 10 MiB read now happens once per cache lifetime rather than once per request,
     * which is the part that mattered.
     *
     * @return array<string, array<string, true>> text domain => set of md5(phrase)
     */
    public function getPhraseIndex()
    {
        if (null !== ($cached = $this->cache->get(PhraseCache::KEY_PHRASE_INDEX))) {
            return $cached;
        }

        //only the two columns the set is built from; the other three were read and
        //discarded on every request
        $select = new Sql($this->adapter);
        $select ->select($this->config['phrases_table_name'])
                ->columns([
                    'text_domain',
                    'phrase',
                ])
                ->where(['project' => $this->config['project_name']]);
        $results = $this->fetchSome($select);

        $return = [];
        foreach ($results as $row) {
            $return[$row['text_domain']][md5($row['phrase'])] = true;
        }

        $this->cache->set(PhraseCache::KEY_PHRASE_INDEX, $return);
        return $return;
    }

    /**
     * Add to the list of translations to add to the database
     * @param array $params
     */
    protected function addMissingPhrase($params)
    {
        //marking it seen keeps a phrase that appears twice on one page from being
        //queued, and inserted, twice
        $this->phraseIndex[$params['text_domain']][md5($params['message'])] = true;
        $this->newMissingPhrases[$params['text_domain']][] = $params['message'];
        return $this;
    }

    /**
     * Note a phrase the translator asked for and the database has never seen.
     *
     * Called from the translator's missing-translation event, so it runs inside page
     * rendering and must stay cheap: the index is loaded once per request and the
     * test is an isset() on a hash rather than a scan over every phrase in the domain.
     *
     * @param array $params
     */
    public function reportMissingTranslation($params)
    {
        if (! $this->phraseIndexLoaded) {
            $this->phraseIndex       = $this->getPhraseIndex();
            $this->phraseIndexLoaded = true;
        }
        if (! isset($this->phraseIndex[$params['text_domain']][md5($params['message'])])) {
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
        if (null !== ($cached = $this->cache->get(PhraseCache::KEY_TRANSLATED_TEXT))) {
            return $cached;
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
        $this->cache->set(PhraseCache::KEY_TRANSLATED_TEXT, $return);
        return $return;
    }

    /**
     * Persist whatever this request discovered, and drop the two derived caches.
     *
     * This is the end-of-request entry point, and it takes a plain string rather than
     * a framework event so that the caller can be anything: the laminas listener wired
     * in TranslationsTableFactory, a Symfony kernel.terminate subscriber, a console
     * command, a test. finishUp(MvcEvent) — which is what used to be here — could only
     * ever be called by laminas-mvc, and that single type hint was most of the reason
     * this module could not be used anywhere else.
     *
     * @param string|null $routeName recorded on new phrases as the place they were
     *        first seen, which is the only clue a translator gets about context
     * @return \Laminas\Db\Adapter\Driver\ResultInterface[]
     */
    public function flush($routeName = null)
    {
        return $this->writeMissingPhrasesToDb($routeName);
    }

    /**
     * Forget both derived caches, in memory and in the persistent store.
     *
     * Replaces removeDependentCacheItems('phrase') from SionCacheTrait. That method
     * walked a persisted map of cache key to invalidating entity name, and JTranslate
     * registered exactly one entity against exactly two keys, so the map could only
     * ever answer "both of them".
     */
    protected function invalidatePhraseCaches()
    {
        $this->cache->clear();
        //the in-request copy of the membership set is derived from the same rows, so
        //it is stale for the same reason and must be re-read rather than reused
        $this->phraseIndex       = [];
        $this->phraseIndexLoaded = false;
    }

    /**
     * Queries the database for the latest translations and rewrites all the files.
     *
     * @param string[]|null $onlyTextDomains restrict to these text domains; null means all
     * @return string[] the absolute paths written, in write order
     * @throws \RuntimeException if any directory or file could not be written. The
     *         caller decides what that means: the admin action reports it to the
     *         translator, the request-path caller logs it and carries on. What must
     *         never happen again is the third option this method used to take, which
     *         was to discard every return value and report success regardless.
     */
    public function writePhpTranslationArrays(?array $onlyTextDomains = null)
    {
        $translations = $this->getTranslatedText();
        $written = [];
        foreach ($translations as $textDomain => $localeTrans) {
            //Restricting to named domains is what makes this usable on a tree where
            //some target directories are not writable — a real state, since the
            //non-module domains live under a directory a deploy may own. Without it,
            //one unwritable domain stops every later domain from being rebuilt.
            if (null !== $onlyTextDomains && ! in_array($textDomain, $onlyTextDomains, true)) {
                continue;
            }
            //a text domain that names a loaded module keeps its export inside that
            //module; every other domain goes to a subfolder of the project's own
            //language/ directory. The old code also tried to mkdir the *module*
            //directory in the branch where the module was known to exist, which
            //could not help and is gone; ensureDirectory() creates recursively.
            $folder = key_exists($textDomain, $this->userModules)
                ? $this->rootDirectory . '/module/' . $textDomain . '/language'
                : $this->rootDirectory . '/language/' . $textDomain;
            $this->ensureDirectory($folder);

            foreach ($localeTrans as $locale => $trans) {
                $code = "<?php\n\nreturn " . $this->exportArray($trans) . ";\n";
                $written[] = $this->writeCatalogAtomically(
                    $folder . '/' . sprintf($this->filePattern, $locale),
                    $code
                );
            }
        }
        return $written;
    }

    /**
     * Create a directory if it is not already there, or fail loudly.
     *
     * The explicit chmod after mkdir() is not redundant: mkdir()'s mode argument is
     * masked by the process umask, so under a common 0022 a 0775 request lands as
     * 0755 and the web server's group loses the write permission it needs for the
     * catalogs about to be created inside.
     *
     * @param string $folder
     * @throws \RuntimeException
     */
    protected function ensureDirectory($folder)
    {
        if (is_dir($folder)) {
            return;
        }
        //the second test covers the race where a concurrent request created it
        //between our is_dir() and our mkdir()
        if (! @mkdir($folder, 0775, true) && ! is_dir($folder)) {
            throw new \RuntimeException(sprintf(
                'Could not create the translation directory %s (%s). Translations are '
                . 'saved in the database but cannot be compiled until this path is '
                . 'writable by the web server user.',
                $folder,
                error_get_last()['message'] ?? 'no error reported'
            ));
        }
        @chmod($folder, 0775);
    }

    /**
     * Write one compiled catalog, atomically, and retire the stale compiled copy.
     *
     * Three properties this needs that a bare file_put_contents() does not provide:
     *
     * - **Atomicity.** These files are include()d by concurrent requests. Writing in
     *   place lets another process compile a half-written file, which surfaces as a
     *   parse error in the middle of an unrelated page — an intermittent failure that
     *   presents as a permissions problem and is not one. A temporary file in the
     *   same directory followed by rename() is atomic on POSIX, so a reader sees
     *   either the whole previous file or the whole new one, never a splice. It also
     *   fixes the common deployment case outright: rename() needs write permission on
     *   the *directory*, not on the existing file, so a catalog left behind by a
     *   deploy running as a different user is now replaceable instead of a hard stop.
     * - **A failure that is visible.** See the class docblock on the caller. Silence
     *   was the actual bug, not the permissions.
     * - **OPcache coherence.** The compiled copy of the previous file outlives the
     *   write by up to opcache.revalidate_freq seconds, and forever if a deployment
     *   ever sets opcache.validate_timestamps=0 — a normal production tuning step
     *   that would otherwise make freshly saved translations permanently invisible.
     *
     * The mode is 0664. Nothing executes these files, and the exec bits the old code
     * set came from reusing the directory's mode for a data file.
     *
     * @param string $fileToWrite
     * @param string $code
     * @return string $fileToWrite
     * @throws \RuntimeException
     */
    protected function writeCatalogAtomically($fileToWrite, $code)
    {
        //deliberately not tempnam(): when the target directory is not writable it
        //silently falls back to the system temp directory, and the rename() below
        //would then cross a filesystem boundary and fail. Building the name from the
        //target path keeps the temporary file in the directory we are committing to.
        $temp = $fileToWrite . '.' . getmypid() . '.tmp';

        if (false === @file_put_contents($temp, $code)) {
            throw new \RuntimeException(sprintf(
                'Could not write the translation catalog %s (%s). The directory must be '
                . 'writable by the web server user.',
                $temp,
                error_get_last()['message'] ?? 'no error reported'
            ));
        }
        @chmod($temp, 0664);

        if (! @rename($temp, $fileToWrite)) {
            $message = error_get_last()['message'] ?? 'no error reported';
            @unlink($temp);
            throw new \RuntimeException(sprintf(
                'Could not move the new translation catalog into place at %s (%s).',
                $fileToWrite,
                $message
            ));
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($fileToWrite, true);
        }

        return $fileToWrite;
    }

    /**
     * Render a translation array as PHP source.
     *
     * This replaces Laminas\Code\Generator\ValueGenerator, which was the only
     * reason this module referenced laminas-code — a package that is not in
     * fact installed, so writePhpTranslationArrays() fatalled with "class not
     * found" for anyone who reached it.
     *
     * The output format deliberately matches the committed *.lang.php files —
     * short array syntax, four-space indent, and positional entries for
     * integer keys that continue the sequence — so regenerating them produces
     * no spurious diff.
     *
     * @param  array<array-key, mixed> $value
     * @return string
     */
    protected function exportArray(array $value, $depth = 1)
    {
        if ([] === $value) {
            return '[]';
        }

        $indent        = str_repeat('    ', $depth);
        $expectedIndex = 0;
        $lines         = [];

        foreach ($value as $key => $item) {
            if (is_int($key) && $key === $expectedIndex) {
                $prefix = '';
                $expectedIndex++;
            } else {
                $prefix = $this->exportValue($key, $depth) . ' => ';
            }
            $lines[] = $indent . $prefix . $this->exportValue($item, $depth) . ',';
        }

        return "[\n" . implode("\n", $lines) . "\n" . str_repeat('    ', $depth - 1) . ']';
    }

    /**
     * @param  mixed $value
     * @return string
     */
    protected function exportValue($value, $depth)
    {
        if (is_array($value)) {
            return $this->exportArray($value, $depth + 1);
        }
        if (null === $value) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        // var_export() single-quotes strings and escapes only \ and ', which is
        // exactly what ValueGenerator emitted.
        return var_export($value, true);
    }

    /**
     * Check the TranslationTable object for new missing translations and write them to the database to be translated
     * @param string $routeName
     * @return \Laminas\Db\Adapter\Driver\ResultInterface[]
     */
    public function writeMissingPhrasesToDb($routeName = null)
    {
        //Nothing was missing, so there is nothing to write. flush() is called at the
        //end of every request whether or not this one discovered a phrase, and
        //everything below is expensive: getTranslations(true) is a 7,766-phrase join
        //plus the entire user directory, measured at 221ms, and the invalidation at
        //the end throws away caches that were still valid. Measured on /en/books,
        //440ms -> 225ms. The 1.0.x line has had this guard since 2022-07-16 (1c055c4);
        //the modernization line forked in 2020 and never picked it up.
        if (empty($this->newMissingPhrases)) {
            return [];
        }
        //'now', not null: passing null here is deprecated since PHP 8.1 and a
        //TypeError in PHP 9, and this line is on the every-request path.
        $dateString = date_format((new \DateTime('now', new \DateTimeZone('UTC'))), 'Y-m-d H:i:s');
        $translations = $this->getTranslations(true);

        $localesToSearch = $this->config['locales_to_translate'];
        if (! in_array($this->config['key_locale'], $localesToSearch)) {
            $localesToSearch[] = $this->config['key_locale'];
        }

        //if we find something, we'll have to write the php arrays
        $weFoundAPreviousMatch = false;
        $result = [];
        foreach ($this->newMissingPhrases as $textDomain => $phrases) {
            foreach ($phrases as $phrase) {
                if (! isset($phrase)) {
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
                            //we found a phrase-locale match
                            if (
                                isset($translationPhrase[$locale])
                                && 0 !== strlen($translationPhrase[$locale])
                            ) {
                                //take the first translation found; there is no way to rank them
                                if (! isset($translationsToInsert[$locale])) {
                                    $weFoundAPreviousMatch = true;
                                    $translationsToInsert[$locale] = [
                                        'translation_phrase_id' => $phrasesKeyId,
                                        'locale' => $locale,
                                        'translation' => $translationPhrase[$locale],
                                        'modified_by' => (isset($translationPhrase[$locale . 'ModifiedBy']) &&
                                            isset($translationPhrase[$locale . 'ModifiedBy']['userId'])) ?
                                            $translationPhrase[$locale . 'ModifiedBy']['userId'] :
                                            $this->getActingUserId(),
                                        'modified_on' => $dateString,
                                    ];
                                }
                            }
                        }
                        if (
                            // we have all the translations we need
                            count($translationsToInsert) === count($localesToSearch)
                        ) {
                            continue;
                        }
                    }
                }

                //auto insert into translations table for the key locale
                if (! isset($translationsToInsert[$this->config['key_locale']])) {
                    $translationsToInsert[$this->config['key_locale']] = [
                        'translation_phrase_id' => $phrasesKeyId,
                        'locale' => $this->config['key_locale'],
                        'translation' => $phrase,
                        'modified_by' => $this->getActingUserId(),
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
        $this->invalidatePhraseCaches();
        if ($weFoundAPreviousMatch) {
            //This runs inside a normal page request, from flush() at
            //MvcEvent::EVENT_FINISH — which laminas fires *before* SendResponseListener
            //(priority -10000) has sent anything. So an exception escaping here would
            //replace whatever page the visitor asked for with a 500, on a request that
            //had nothing to do with translating. Trading a silent export failure for an
            //availability failure is not an improvement: log it and let the page render.
            //The admin action calls writePhpTranslationArrays() directly and does want
            //the exception, which is why the catch lives here and not in the method.
            try {
                $this->writePhpTranslationArrays();
            } catch (\RuntimeException $e) {
                error_log('JTranslate: could not compile translation files after '
                    . 'discovering new phrases: ' . $e->getMessage());
            }
        }
        return $result;
    }

    public function setUserModules($userModules)
    {
        $this->userModules = $userModules;
        return $this;
    }

    /**
     * @return UserDirectoryInterface
     * @throws \RuntimeException when no directory was injected. Only the admin listing
     *         needs one, so a deployment that never opens the translation GUI can leave
     *         it out; asking for it anyway is a wiring mistake worth reporting.
     */
    public function getUserTable()
    {
        if (! $this->userTable) {
            throw new \RuntimeException(
                'No UserDirectoryInterface was given to TranslationsTable, so translations '
                . 'cannot be attributed to a user.'
            );
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
        if (! isset($gateway)) {
            $gateway = $this->phrasesGateway;
        }
        if (! isset($where) && ! isset($sql)) {
            throw new \InvalidArgumentException('No query requested.');
        }
        if (isset($sql)) {
            if (! isset($sqlArgs)) {
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

    public static function getLocaleNames()
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
