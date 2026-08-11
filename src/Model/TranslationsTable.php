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
use JTranslate\Model\PhraseIdentity;

class TranslationsTable extends AbstractTableGateway implements AdapterAwareInterface
{
    /**
     * Every phrase this project has, as [text domain => [hex hash => is retired]].
     *
     * @see getPhraseIndex() for why it holds hashes rather than the phrases, and why
     *      the value is a retirement flag rather than a bare `true`.
     * @var array<string, array<string, bool>> $phraseIndex
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
     * Whether `trans_translations_history` is present, resolved once per request.
     *
     * Null until asked. An installation that has not run M006 has no such table, and
     * this is on the path a human takes when they press Save — one SHOW TABLES per
     * request that edits, not one per translation written.
     *
     * @var bool|null
     */
    protected $historyTableExists;

    /**
     * The width of `trans_translations_history.notes`, in characters.
     *
     * Characters, not bytes: the column is `VARCHAR(255)` under `utf8mb4`, so MySQL
     * counts what mb_substr() counts. A note written in Portuguese would otherwise be
     * cut short of the limit it was told it had.
     */
    public const NOTE_LENGTH = 255;

    /**
     * The `locale` of a history row that is about the phrase rather than a language.
     *
     * The empty string, which no translation row can hold, so it cannot be mistaken for
     * one. Retirement and un-retirement use it. A language-filtered read includes these
     * deliberately: an event about the whole phrase belongs in every language's thread.
     */
    public const PHRASE_EVENT_LOCALE = '';

    /**
     * The values `trans_translations_history.operation` takes.
     *
     * They split on **what the event is about**, and that split is the one distinction a
     * reader of this table has to get right:
     *
     * - `update` and `retract` are about a **translation**. One replaced text with other
     *   text; the other removed a language's text altogether. Both destroy something, both
     *   name a `locale`, and both carry the text they destroyed in `old_translation`.
     * - `retire` and `unretire` are about the **phrase**. They move a row on and off the
     *   translator's worklist and destroy nothing at all: every translation stays, the
     *   compiled catalogs still carry it, and the site renders exactly what it rendered
     *   before. Their `locale` is {@see self::PHRASE_EVENT_LOCALE} and their
     *   `old_translation` is `''`, because there is no language and nothing was lost.
     *
     * A caller that treats `retire` as a data loss will report an incident that did not
     * happen; one that treats `retract` as a worklist change will lose a translation and
     * think it tidied up. See {@see updatePhrase()} for the write side of the same line.
     */
    public const OPERATION_UPDATE   = 'update';
    public const OPERATION_RETRACT  = 'retract';
    public const OPERATION_RETIRE   = 'retire';
    public const OPERATION_UNRETIRE = 'unretire';

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

    /**
     * The compiled catalog filename, with `%s` standing in for the locale.
     *
     * Configurable through `jtranslate.catalog_file_pattern`, defaulting to what it has
     * always been. It has to agree with the `translation_file_patterns` the translator is
     * configured with — this side writes the files and that side reads them — so an
     * installation changing it must change both, which is why it is one key rather than
     * an argument to writePhpTranslationArrays().
     *
     * @var string $filePattern
     */
    protected $filePattern = '%s.lang.php';

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
     * @throws \RuntimeException when `project_name` is missing or empty. Every read and
     *         write in this class scopes by it, and this table is shared between the
     *         applications using this library — so a missing value would not degrade
     *         gracefully, it would silently address another project's rows. M002 has
     *         refused to run without it since it was written; the model now agrees.
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
        if (! isset($config['project_name']) || '' === $config['project_name']) {
            throw new \RuntimeException(
                'jtranslate.project_name is not configured, so there is no project to read or write phrases '
                . 'for. Several applications may share one phrase table and this string is what separates '
                . 'them; defaulting it would mean reading and writing another project\'s rows.'
            );
        }

        $this->phrasesGateway       = $phrasesGateway;
        $this->translationsGateway  = $translationsGateway;
        $this->adapter              = $phrasesGateway->getAdapter();
        $this->config               = $config;
        $this->cache                = $cache;
        $this->actingUserProvider   = $actingUserProvider;
        $this->userTable            = $userTable;
        $this->newMissingPhrases    = [];

        if (isset($config['catalog_file_pattern']) && '' !== $config['catalog_file_pattern']) {
            $this->filePattern = (string) $config['catalog_file_pattern'];
        }

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
     * Write as this user, whatever the ambient identity says.
     *
     * The configured provider reads the host application's session, and a caller that
     * has no session still has an identity: an API request authenticates with a bearer
     * token, resolves it to a user id, and every row it writes has to carry that id or
     * `modified_by` is NULL and the admin listing cannot say who wrote a translation.
     * Attribution is most of the reason an automated agent is allowed to write at all.
     *
     * Named after `SionModel\Db\Model\SionTable::setActingUserId()` on purpose: the
     * association API already reaches for that method and both surfaces should read
     * the same.
     *
     * The provider is replaced rather than a field being set, so the "resolve at call
     * time" contract on ActingUserProviderInterface still holds — the value is just
     * fixed. Scoped to this instance, which on a Symfony-served request is scoped to
     * the request.
     *
     * @param int|null $userId
     * @return self
     */
    public function setActingUserId($userId)
    {
        $id = null === $userId ? null : (int) $userId;
        $this->actingUserProvider = new \JTranslate\Service\Adapter\CallableActingUserProvider(
            static function () use ($id) {
                return $id;
            }
        );

        return $this;
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
    /**
     * @param bool $fromAllProjects
     * @param bool $includeRetired retired phrases are excluded by default, because the
     *        callers are the admin listing — which must not ask anyone to translate a
     *        phrase that has left the project — and writeMissingPhrasesToDb(), which
     *        uses this to find an existing translation of the same text in another text
     *        domain. The second one is worth thinking about: a retired row's
     *        translation is still a perfectly good translation of that string, but
     *        seeding from it would silently resurrect the editorial content of a
     *        retired phrase into a live one, and the translator would have no way to
     *        see where it came from. Better to leave the new phrase untranslated and
     *        visible as work.
     * @return array
     */
    public function getTranslations($fromAllProjects = false, $includeRetired = false)
    {
//         $cacheKey = 'translations';
//         if ($fromAllProjects) {
//             $cacheKey.='-from-all-projects';
//         }
        $sql = "SELECT t.`translation_id`,p.`translation_phrase_id`, t.`locale`,t.`translation`,
t.`modified_by`,t.`modified_on`, p.`text_domain`,  p.`phrase`, p.`added_on`, p.`project`, p.`origin_route`,
p.`retired_on`
FROM `trans_phrases` p
LEFT JOIN `trans_translations` t ON p.`translation_phrase_id` = t.`translation_phrase_id`"
        . ($includeRetired ? '' : "\nWHERE p.`retired_on` IS NULL") . "
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
                    'retiredOn'   => $row['retired_on'],
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
        //retired phrases are not outstanding work, so they are not counted — the badge
        //this feeds is a call to action
        $sql = "SELECT p.`translation_phrase_id`, COUNT(*) AS PhraseLocaleCount
FROM `trans_phrases` p
LEFT JOIN `trans_translations` t ON p.`translation_phrase_id` = t.`translation_phrase_id`
WHERE (`project` = ?) AND p.`retired_on` IS NULL
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
        return $this->getPhraseById((int) $id);
    }

    /**
     * The criteria countPhrases() and getPhrasePage() understand.
     *
     * Named here rather than accepted as free-form SQL because every one of them ends
     * up in a WHERE clause: a caller that could pass an arbitrary column name could
     * read across the `project` boundary, which is the one thing this table must never
     * allow. See the scoping note on phraseCriteria().
     */
    public const CRITERIA = [
        'textDomain',
        'originRoute',
        'search',
        'untranslatedIn',
        'translatedIn',
        'includeRetired',
        'onlyRetired',
        'originRouteLike',
    ];

    /**
     * How many phrases of this project match, ignoring paging.
     *
     * @param array $criteria a subset of self::CRITERIA
     * @return int
     */
    public function countPhrases(array $criteria = [])
    {
        $sql    = new Sql($this->adapter);
        $select = $sql->select(['p' => $this->config['phrases_table_name']])
            ->columns(['total' => new \Laminas\Db\Sql\Expression('COUNT(*)')]);
        $this->applyPhraseCriteria($select, $criteria);

        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return is_array($row) ? (int) $row['total'] : 0;
    }

    /**
     * One page of this project's phrases, hydrated with their translations.
     *
     * ## Why this exists next to getTranslations()
     *
     * getTranslations() reads every phrase and every translation of the project into
     * one array and filters in PHP. Measured on this database it is **0.220 s and
     * 72.6 MB peak** for 6,874 phrases, it memoizes nothing, and getPhrase() used to
     * call it to return a single row — so reading one phrase cost the whole table.
     * That is affordable once on an admin listing that genuinely shows everything and
     * not affordable per request on an API, and it cannot express the question an API
     * caller actually asks ("which German translations are missing in this text
     * domain"), because filtering in PHP happens after the paging decision has already
     * been made.
     *
     * getTranslations() is left exactly as it is: the admin listing wants all of it,
     * and rewriting the GUI is not this change.
     *
     * ## Two queries, not one join
     *
     * A phrase joined to its translations yields one row per locale, so `LIMIT 100`
     * over the join returns some number of phrases between 20 and 100 — paging a
     * joined result set silently pages the wrong thing. The phrase rows are selected
     * and paged first, and their translations fetched by id afterwards.
     *
     * @param array $criteria a subset of self::CRITERIA
     * @param int $limit
     * @param int $offset
     * @return array phrase id => phrase record, in display order
     */
    public function getPhrasePage(array $criteria = [], $limit = 100, $offset = 0)
    {
        $sql    = new Sql($this->adapter);
        $select = $sql->select(['p' => $this->config['phrases_table_name']])
            ->columns([
                'translation_phrase_id',
                'text_domain',
                'phrase',
                'added_on',
                'origin_route',
                'retired_on',
            ])
            //Ordered by the primary key last, so the sequence is total even when two
            //phrases share a text domain and a text. Without a tie-break MySQL may
            //return the same row on page 1 and page 2 and skip another entirely.
            ->order(['text_domain' => 'ASC', 'phrase' => 'ASC', 'translation_phrase_id' => 'ASC'])
            ->limit((int) $limit)
            ->offset((int) $offset);
        $this->applyPhraseCriteria($select, $criteria);

        $rows = [];
        foreach ($sql->prepareStatementForSqlObject($select)->execute() as $row) {
            $rows[] = $row;
        }

        return $this->hydratePhrases($rows);
    }

    /**
     * One phrase of this project, or null.
     *
     * The shape is the one getTranslations() produces for a single entry, because
     * updatePhrase() reads `$phrase[$locale . 'Id']` out of it to decide between an
     * UPDATE and an INSERT — and, more importantly, to decide *which row* to write.
     * That indirection is the security property documented on updatePhrase(); a
     * gratuitously different shape here would have meant reimplementing it.
     *
     * The one addition is `{locale}ModifiedById`, the raw user id. getTranslations()
     * answers `{locale}ModifiedBy` as a whole user record from the user directory,
     * which costs a full user load and hands a caller more than it asked for. Both
     * keys are set here so the admin listing and the API each read the one they mean.
     *
     * **Project-scoped, like every other read here.** A phrase id is global to a table
     * three projects share, so fetching by id alone would answer with another
     * project's phrase for anyone who guessed an integer.
     *
     * @param int $id
     * @return array|null
     */
    public function getPhraseById($id)
    {
        $sql    = new Sql($this->adapter);
        $select = $sql->select(['p' => $this->config['phrases_table_name']])
            ->columns([
                'translation_phrase_id',
                'text_domain',
                'phrase',
                'added_on',
                'origin_route',
                'retired_on',
            ])
            ->where([
                'p.translation_phrase_id' => (int) $id,
                'p.project'               => $this->config['project_name'],
            ]);

        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();
        if (! is_array($row)) {
            return null;
        }

        $hydrated = $this->hydratePhrases([$row]);

        return $hydrated[(int) $id] ?? null;
    }

    /**
     * Attach the translation rows to a set of phrase rows.
     *
     * @param array $rows raw trans_phrases rows
     * @return array phrase id => phrase record, in the order given
     */
    protected function hydratePhrases(array $rows)
    {
        if ([] === $rows) {
            return [];
        }

        $return = [];
        $ids    = [];
        foreach ($rows as $row) {
            $id         = (int) $row['translation_phrase_id'];
            $ids[]      = $id;
            $return[$id] = [
                'phraseId'    => $id,
                'textDomain'  => $row['text_domain'],
                'phrase'      => $row['phrase'],
                'originRoute' => $row['origin_route'],
                'addedOn'     => $row['added_on'],
                'retiredOn'   => $row['retired_on'],
            ];
        }

        $sql    = new Sql($this->adapter);
        $select = $sql->select($this->config['translations_table_name'])
            ->columns([
                'translation_id',
                'translation_phrase_id',
                'locale',
                'translation',
                'modified_by',
                'modified_on',
            ])
            ->where(['translation_phrase_id' => $ids]);

        $utc = new \DateTimeZone('UTC');
        foreach ($sql->prepareStatementForSqlObject($select)->execute() as $row) {
            $id     = (int) $row['translation_phrase_id'];
            $locale = $row['locale'];
            if (! isset($return[$id]) || null === $locale) {
                continue;
            }
            $return[$id][$locale]                  = $row['translation'];
            $return[$id][$locale . 'Id']           = $row['translation_id'];
            $return[$id][$locale . 'ModifiedById'] = isset($row['modified_by']) ? (int) $row['modified_by'] : null;
            $return[$id][$locale . 'ModifiedOn']   = isset($row['modified_on'])
                ? \DateTime::createFromFormat('Y-m-d H:i:s', $row['modified_on'], $utc)
                : null;
        }

        return $return;
    }

    /**
     * Narrow a phrase select to this project and to the caller's criteria.
     *
     * **The project predicate is not a criterion and cannot be turned off.**
     * `trans_phrases` is shared — this database holds phrases for three projects — and
     * a phrase is arbitrary text taken from whatever the other project renders, so it
     * can carry information that project's users never agreed to publish here. Every
     * read path in this class that is reachable from a request scopes by
     * `project_name`, and this method is the one place a new read path gets it for
     * free. getTranslations($fromAllProjects = true) is the deliberate exception and
     * is reachable only from the admin GUI.
     *
     * @param \Laminas\Db\Sql\Select $select
     * @param array $criteria
     * @return void
     */
    protected function applyPhraseCriteria($select, array $criteria)
    {
        $where = new Where();
        $where->equalTo('p.project', $this->config['project_name']);

        //Retired phrases are hidden by default, because the whole purpose of retiring
        //one is to stop asking a translator to work on it. `includeRetired` is opt-in
        //rather than the default for the same reason the project predicate cannot be
        //turned off at all: a listing that quietly grows by 5,000 dead rows because
        //somebody forgot a flag is worse than one that needs the flag spelled out.
        if (! empty($criteria['onlyRetired'])) {
            $where->isNotNull('p.retired_on');
        } elseif (empty($criteria['includeRetired'])) {
            $where->isNull('p.retired_on');
        }

        if (isset($criteria['textDomain']) && '' !== $criteria['textDomain']) {
            $where->equalTo('p.text_domain', $criteria['textDomain']);
        }
        if (isset($criteria['originRoute']) && '' !== $criteria['originRoute']) {
            $where->equalTo('p.origin_route', $criteria['originRoute']);
        }
        //A separate criterion from `originRoute` rather than an option on it, because
        //the two differ in whether the caller's `%` and `_` are wildcards. `search`
        //escapes them; this one deliberately does not, since a caller asking for
        //'blog%' means the wildcard. That makes it a bulk-administration criterion and
        //not something to expose to an HTTP query parameter.
        if (isset($criteria['originRouteLike']) && '' !== $criteria['originRouteLike']) {
            $where->like('p.origin_route', $criteria['originRouteLike']);
        }
        if (isset($criteria['search']) && '' !== $criteria['search']) {
            //Escaped by hand: laminas-db parameterizes the value but LIKE reads % and _
            //out of the *value*, so an unescaped search for "100%" matches everything.
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $criteria['search']);
            $where->like('p.phrase', '%' . $escaped . '%');
        }

        //"Has no usable translation in this locale" — no row, or a row holding the
        //empty string. Both exist in this table and both mean the same thing to a
        //translator, so a filter that only tested for the missing row would hand an
        //agent a list that silently omits the phrases someone blanked.
        $translations = $this->config['translations_table_name'];
        if (isset($criteria['untranslatedIn']) && '' !== $criteria['untranslatedIn']) {
            $where->addPredicate(new \Laminas\Db\Sql\Predicate\Expression(
                'NOT EXISTS (SELECT 1 FROM `' . $translations . '` tx'
                . ' WHERE tx.translation_phrase_id = p.translation_phrase_id'
                . ' AND tx.locale = ? AND tx.translation <> \'\')',
                [$criteria['untranslatedIn']]
            ));
        }
        if (isset($criteria['translatedIn']) && '' !== $criteria['translatedIn']) {
            $where->addPredicate(new \Laminas\Db\Sql\Predicate\Expression(
                'EXISTS (SELECT 1 FROM `' . $translations . '` tx'
                . ' WHERE tx.translation_phrase_id = p.translation_phrase_id'
                . ' AND tx.locale = ? AND tx.translation <> \'\')',
                [$criteria['translatedIn']]
            ));
        }

        $select->where($where);
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
     * ## Three distinct submissions, and why `''` is not one of them
     *
     * A locale in $data can mean three things, and the difference matters because two
     * of the callers are a browser form and an HTTP API with opposite conventions:
     *
     * - **absent, or the empty string** — "leave this locale alone". This is the web
     *   form's meaning and it is not negotiable: every locale is rendered as a textarea
     *   on every edit, so an untouched form posts `''` for every language the translator
     *   did not fill in. If `''` meant "clear", opening a phrase and saving one language
     *   would wipe the other three. That is the single most destructive thing this method
     *   could plausibly be made to do.
     * - **an explicit null** — "retract this translation". Deletes the row. Deleting
     *   rather than storing `''` keeps one representation of "untranslated" in the table
     *   instead of two; `untranslatedIn` already has to test for both because older code
     *   created blanks.
     *
     *   **A retraction is not a retirement, and the two are easy to reach for by mistake.**
     *   This deletes one language's text and the phrase stays on the worklist — indeed it
     *   moves *up* it, because the language is now a gap. {@see retirePhraseById()} takes
     *   the phrase off the worklist and touches no translation at all. So retracting every
     *   language to make a row go away does the opposite of that while destroying five
     *   translations on the way: the row remains, wholly untranslated, at the top of the
     *   list. If the goal is "nobody should be asked to translate this", retire it.
     *
     *   This is the *table's* convention and it stays. What changed on 2026-08-11 is one
     *   layer up: `PhrasesV3Controller` no longer lets a caller reach it by sending a
     *   null, because a null is what a serializer emits for an absent field. An API
     *   retraction now takes the explicit `_retract` key and arrives here as this null —
     *   so the destructive spelling is deliberate at the surface and unchanged
     *   underneath.
     * - **any other string** — write it. Including `'0'`, which the old condition
     *   (`! $data[$key]`) silently discarded along with `''`, because both are falsy in
     *   PHP. A translation of literally "0" is legitimate — it is a plausible rendering
     *   of a label in any language — and it could not be saved.
     *
     * The old code collapsed the first and third cases into a single falsy test, so
     * there was no way to express retraction at all and `'0'` was unsaveable.
     * `PhrasesV3Controller` documented the quirk and mirrored it deliberately so its
     * `changed` field would not lie; that mirror is now removed there in step with this.
     *
     * @param int $id
     * @param array $data locale => string to write, null to retract; absent or '' to
     *        leave alone
     * @return array
     */
    public function updatePhrase($id, $data, $notes = null)
    {
        //getPhraseById(), not getTranslations()[$id]: the same record for this purpose
        //— it carries the {locale}Id keys the write below steers by — for one phrase's
        //worth of query instead of the project's. The security property is unchanged
        //and is the reason this is a lookup at all: see the docblock above.
        $phrase = $this->getPhraseById((int) $id);
        if (null === $phrase) {
            //Previously an "Undefined array key" warning followed by a null-dereference
            //further down. An id this project has no phrase for is a caller error and
            //has to say so, because the API is now a caller.
            throw new \InvalidArgumentException(
                'No phrase with id ' . (int) $id . ' belongs to this project.'
            );
        }
        $dateString = date_format((new \DateTime('now', new \DateTimeZone('UTC'))), 'Y-m-d H:i:s');

        $locales = array_keys($this->getLocales(true));
        $results = [];
        //Resolved once, before the first write, for the reason set out on
        //writeMissingPhrasesToDb(): the configured provider can raise rather than answer,
        //and a provider that raises between two of the writes below leaves a phrase
        //half-edited.
        $actingUserId = $this->getActingUserId();
        foreach ($locales as $key) {
            //"Leave this locale alone": not submitted at all, or submitted as the empty
            //string, which is what an untouched textarea posts. See the docblock — this
            //is the case that must never delete anything.
            if (! array_key_exists($key, $data) || '' === $data[$key]) {
                continue;
            }

            $hasRow = isset($phrase[$key . 'Id']) && $phrase[$key . 'Id'];

            //"Retract this translation." Only an explicit null means this, so no browser
            //can reach it. Deleted rather than blanked so that "untranslated" has one
            //representation in the table.
            if (null === $data[$key]) {
                if (! $hasRow) {
                    continue;
                }
                //Before the delete, never after: the row is the only copy.
                $this->recordTranslationHistory(
                    (int) $phrase[$key . 'Id'],
                    self::OPERATION_RETRACT,
                    $actingUserId,
                    $dateString,
                    $notes
                );
                $sql    = new Sql($this->adapter);
                $delete = $sql->delete($this->config['translations_table_name'])
                    ->where(['translation_id' => $phrase[$key . 'Id']]);
                $results[] = $sql->prepareStatementForSqlObject($delete)->execute();
                continue;
            }

            //Strict comparison against what is stored, so submitting the text that is
            //already there is not a write. Both sides are strings here.
            if (isset($phrase[$key]) && (string) $data[$key] === (string) $phrase[$key]) {
                continue;
            }

            if ($hasRow) {
                //update don't insert
                //
                //An overwrite is the one operation here with no reversible form: the
                //text it replaces exists nowhere else. Copied first, so a failed insert
                //below leaves a history row for a change that did not happen — which is
                //the harmless direction, and the only one available without a
                //transaction this method has never had.
                $this->recordTranslationHistory(
                    (int) $phrase[$key . 'Id'],
                    self::OPERATION_UPDATE,
                    $actingUserId,
                    $dateString,
                    $notes
                );
                $sql = new Sql($this->adapter);
                $update = $sql->update($this->config['translations_table_name'])
                    ->set([
                        'translation' => $data[$key],
                        'modified_on' => $dateString,
                        'modified_by' => $actingUserId,
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
                    'modified_by' => $actingUserId,
                ]);
                $statement = $sql->prepareStatementForSqlObject($insert);
                $results[] = $statement->execute();
            }
        }
        $this->invalidatePhraseCaches();
        return $results;
    }

    /**
     * Copy a translation row into the append-only history, before something destroys it.
     *
     * Reads the row rather than taking the caller's copy of it, because the caller's
     * copy comes from `getPhraseById()` and carries the text but not `modified_by` or
     * `modified_on` — and losing the attribution is most of what makes an overwrite
     * unrecoverable in practice. One extra SELECT per destructive write, on a path that
     * runs when a human presses Save.
     *
     * Silent when the history table is absent, which is the state of any installation
     * that has not run M006. The alternative — refusing the edit — would make a library
     * upgrade break translating until somebody with DDL rights got round to it, and the
     * schema step is deliberately not the web user's to take. See MigrationInterface.
     *
     * @param int $translationId
     * @param string $operation 'update' or 'retract'
     * @param int|null $actingUserId who is destroying it
     * @param string $now UTC 'Y-m-d H:i:s', the same instant the write below records
     * @param string|null $notes why, from the caller. Truncated at the column width
     *        rather than refused: a note is an aid, and losing the tail of one is a
     *        smaller harm than refusing the translation it explains.
     */
    protected function recordTranslationHistory($translationId, $operation, $actingUserId, $now, $notes = null)
    {
        $history = $this->config['translations_history_table_name'] ?? 'trans_translations_history';
        if (! $this->hasHistoryTable()) {
            return;
        }

        $note = null === $notes || '' === $notes ? null : mb_substr((string) $notes, 0, self::NOTE_LENGTH);

        //Joined to `trans_phrases` so the row carries the thread key — project and
        //phrase_hash — and not just the phrase id, which merges and rediscovery move.
        //The *stored* hash, deliberately: the read below matches against the same column
        //of the same table, so the two agree by construction rather than by both
        //happening to call the same hash function.
        $sql = sprintf(
            'INSERT INTO `%s` (`project`, `phrase_hash`, `locale`, `text_domain`, '
            . '`translation_phrase_id`, `old_translation`, `operation`, '
            . '`notes`, `written_by`, `written_on`, `replaced_by`, `replaced_on`) '
            . 'SELECT p.`project`, p.`phrase_hash`, t.`locale`, p.`text_domain`, '
            . 't.`translation_phrase_id`, t.`translation`, ?, ?, '
            . 't.`modified_by`, t.`modified_on`, ?, ? '
            . 'FROM `%s` t '
            . 'INNER JOIN `%s` p ON p.`translation_phrase_id` = t.`translation_phrase_id` '
            . 'WHERE t.`translation_id` = ?',
            $history,
            $this->config['translations_table_name'],
            $this->config['phrases_table_name']
        );
        $this->adapter->query($sql, [$operation, $note, $actingUserId, $now, $translationId]);
    }

    /**
     * What was destroyed, for one phrase, newest first.
     *
     * Project-scoped through the phrase, like every other read here: three other
     * projects share these tables and an id in a URL must not reach across.
     *
     * Keyed on the phrase *hash*, so the thread survives everything that moves a phrase
     * id — M004's and M005's merges, and a `deletePhrase()` followed by the next render
     * rediscovering the same string as a new row. See M006CreateTranslationHistory.
     *
     * Every text domain the string appears in, deliberately: the same English string in
     * `Schoenstatt` and `default` is one translation problem, and splitting the thread by
     * domain would show half the argument.
     *
     * @param int $phraseId any live row of the string; only its hash is used
     * @param string|null $locale one language's thread, or null for all of them
     * @return list<array<string, mixed>> empty when the phrase is not this project's,
     *         so a caller cannot use this to learn that some other project has one
     */
    public function getTranslationHistory($phraseId, $locale = null)
    {
        $history = $this->config['translations_history_table_name'] ?? 'trans_translations_history';
        //No table, no thread — and no fatal on the edit screen or on /api/v3. See
        //getHistoryCounts(). An empty thread and an absent table are indistinguishable
        //to a caller on purpose: both mean "nothing to show", and only a deploy in
        //progress can produce the second.
        if (! $this->hasHistoryTable()) {
            return [];
        }

        //Resolved to the phrase's hash, which is the thread key — see
        //M006CreateTranslationHistory on why the id is not. This lookup is also the
        //project scope: an id belonging to another project resolves to nothing, so the
        //answer is an empty thread rather than somebody else's.
        $sql = sprintf(
            'SELECT `project`, `phrase_hash` FROM `%s` WHERE `translation_phrase_id` = ? AND `project` = ?',
            $this->config['phrases_table_name']
        );
        $phrase = $this->adapter->query($sql, [(int) $phraseId, $this->config['project_name']])->current();
        if (! is_array($phrase) && ! $phrase instanceof \ArrayObject) {
            return [];
        }
        $phrase = (array) $phrase;

        $parameters = [$phrase['project'], $phrase['phrase_hash']];
        $where      = 'h.`project` = ? AND h.`phrase_hash` = ?';
        if (null !== $locale && '' !== $locale) {
            //`OR locale = ''` keeps the phrase-level events — a retirement — in every
            //language's thread. They are not about a language, and dropping them from a
            //filtered read would mean the one view most likely to be consulted is the one
            //that cannot say why the phrase left the worklist.
            $where       .= ' AND (h.`locale` = ? OR h.`locale` = ?)';
            $parameters[] = $locale;
            $parameters[] = self::PHRASE_EVENT_LOCALE;
        }

        //`history_id` descending, not `replaced_on`: two writes inside the same second
        //are ordinary in a batch, and a timestamp cannot order them. The id can, and
        //an append-only table's id order *is* its event order.
        $sql = sprintf(
            'SELECT h.`history_id`, h.`locale`, h.`text_domain`, h.`translation_phrase_id`, '
            . 'h.`old_translation`, h.`operation`, h.`notes`, '
            . 'h.`written_by`, h.`written_on`, h.`replaced_by`, h.`replaced_on` '
            . 'FROM `%s` h WHERE %s ORDER BY h.`history_id` DESC',
            $history,
            $where
        );

        //Resolved here rather than in the view, because the view has an integer and no
        //way to turn it into a person. Same directory the listing attributes
        //translations with, so a name is spelled the same on both screens.
        $users = $this->getUserTable()->getUsers();

        $rows = [];
        foreach ($this->adapter->query($sql, $parameters) as $row) {
            $row               = (array) $row;
            $row['writtenBy']  = $this->userName($users, $row['written_by'] ?? null);
            $row['replacedBy'] = $this->userName($users, $row['replaced_by'] ?? null);
            $rows[]            = $row;
        }

        return $rows;
    }

    /**
     * A display name for a user id, or null.
     *
     * Null for an id nobody has a name for, which is a real case and not an error: an
     * account can be deleted long after the edit it made, and the API's own writes carry
     * a bot account whose username is what the listing shows.
     *
     * @param array<int, array<string, mixed>> $users
     * @param mixed $userId
     * @return string|null
     */
    private function userName(array $users, $userId)
    {
        if (null === $userId || '' === $userId) {
            return null;
        }

        $user = $users[(int) $userId] ?? null;

        return is_array($user) && isset($user['username']) ? (string) $user['username'] : null;
    }

    /**
     * How many history entries each phrase of this project has, keyed by phrase id.
     *
     * One query for the whole listing, which is what makes the marker in the admin
     * table affordable. The alternative — asking per row — is 1,700 queries on a page
     * that already renders 1,700 rows, and the marker is not worth that.
     *
     * Grouped in the database rather than counted in PHP: the join is
     * `(project, phrase_hash)`, the leading columns of the `thread` index, so the
     * server answers from the index and returns one small row per phrase that has any
     * history at all. Phrases with none are absent rather than zero — the caller wants
     * `isset()`, and an entry per phrase would make this as large as the listing.
     *
     * Keyed by phrase id and not by hash because the listing is: the view has ids and
     * would otherwise have to learn what a phrase hash is to ask a question about an
     * icon.
     *
     * @return array<int, int> phrase id => number of entries, phrases with none omitted
     */
    public function getHistoryCounts()
    {
        $history = $this->config['translations_history_table_name'] ?? 'trans_translations_history';
        //An installation that has not run M006 has no such table, and the listing must
        //still render — the marker is an aid, and a deploy that reached the code before
        //the schema would otherwise 500 the whole translation admin area. Same guard as
        //every write to this table.
        if (! $this->hasHistoryTable()) {
            return [];
        }
        $sql     = sprintf(
            'SELECT p.`translation_phrase_id` AS `phrase_id`, COUNT(*) AS `entries` '
            . 'FROM `%s` p '
            . 'INNER JOIN `%s` h ON h.`project` = p.`project` AND h.`phrase_hash` = p.`phrase_hash` '
            . 'WHERE p.`project` = ? '
            . 'GROUP BY p.`translation_phrase_id`',
            $this->config['phrases_table_name'],
            $history
        );

        $counts = [];
        foreach ($this->adapter->query($sql, [$this->config['project_name']]) as $row) {
            $row                            = (array) $row;
            $counts[(int) $row['phrase_id']] = (int) $row['entries'];
        }

        return $counts;
    }

    /**
     * Retire the phrase for an exact string, if this project has one.
     *
     * For content the application knows has been *superseded* — a record field whose
     * text a moderator has just replaced. The old string is still a perfectly good
     * phrase row with perfectly good translations, and nothing renders it any more, so
     * it sits at the top of a translator's worklist asking for work that will never be
     * seen. See Schoenstatt\Model\SchoenstattTable::retireSupersededPhrases() for the
     * only caller.
     *
     * Retired, never deleted, and that is what makes it safe to do automatically: the
     * row and its translations stay, `--undo` reverses it, and the phrase keeps
     * rendering. If the string comes back — a moderator reverting an edit — the next
     * render that misses clears `retired_on` on its own, because the discovery insert
     * is an `ON DUPLICATE KEY UPDATE` that does exactly that. So a wrong guess here
     * heals itself rather than needing to be noticed.
     *
     * @param string $phrase the exact text, normalized the way a stored phrase is
     * @param string $textDomain
     * @return bool whether a live row was found and retired
     */
    public function retirePhraseByText($phrase, $textDomain, $reason = null)
    {
        $hash = PhraseIdentity::raw((string) $phrase);

        //Read first, and only act on a row that is *live*. That test is what bounds this
        //to one event per state change: retiring an already-retired phrase matches
        //nothing, so it writes no history row however many times it is called. An
        //application that calls this on every save of an unchanged record therefore
        //produces one row the first time and none afterwards.
        //
        //It is also why nothing here can cycle. Retirement writes to `trans_phrases` and
        //to the history table, and neither is read by anything that could retire again:
        //the only path back to `retired_on` is discovery clearing it, which happens when
        //a render misses on the string, and a render is not something this can cause.
        //Retire and rediscover can alternate — a moderator reverting an edit — but each
        //step needs an event outside this method, and each writes one row.
        $sql = sprintf(
            'SELECT `translation_phrase_id`, `project`, `text_domain`, `phrase_hash` FROM `%s` '
            . 'WHERE `project` = ? AND `text_domain` = ? AND `phrase_hash` = ? AND `retired_on` IS NULL',
            $this->config['phrases_table_name']
        );
        $row = $this->adapter->query($sql, [
            $this->config['project_name'],
            $textDomain,
            $hash,
        ])->current();
        if (null === $row) {
            return false;
        }
        $row = (array) $row;

        $sql = sprintf(
            'UPDATE `%s` SET `retired_on` = UTC_TIMESTAMP() WHERE `translation_phrase_id` = ?',
            $this->config['phrases_table_name']
        );
        $this->adapter->query($sql, [$row['translation_phrase_id']]);

        $this->recordPhraseEvent($row, self::OPERATION_RETIRE, $reason);

        //The listing and the compiled catalogs both read a cached view of this.
        $this->invalidatePhraseCaches();

        return true;
    }

    /**
     * Retire one phrase by id, recording why.
     *
     * The surface an *agent* retires through, where {@see retirePhraseByText()} is the one
     * the application uses when it knows a record's text has been superseded and
     * {@see retire()} is the blunt bulk instrument. The difference that matters is the
     * reason: this is a **judgement about a row** — somebody has decided the phrase should
     * not have been filed — and the reason is the only record of what was believed.
     *
     * What makes it safe to let a judgement like that be made cheaply is that retirement
     * destroys nothing and repairs itself; the argument is on {@see retire()} and is not
     * repeated here. What is worth repeating is the asymmetry with the other destructive
     * verb on this class: **retracting removes a translation and cannot be undone by the
     * site, retiring removes a phrase from a worklist and is undone by the site.** They are
     * not two strengths of the same operation.
     *
     * Read-first, and only a *live* row is acted on. That bounds this to one event per
     * state change: retiring an already-retired phrase matches nothing and writes no
     * second history row however many times it is called, so a caller may retry.
     *
     * Project-scoped, because an id alone addresses a table three applications share.
     *
     * @param int $id
     * @param string|null $reason why, from the caller. The whole point; a null is accepted
     *        because this class cannot make its callers' policy, and the v3 API's retire
     *        endpoint requires one.
     * @return bool whether a live row of this project was found and retired
     */
    public function retirePhraseById($id, $reason = null)
    {
        return $this->setRetirementById((int) $id, true, $reason);
    }

    /**
     * Bring one retired phrase back by id, recording why.
     *
     * The reverse judgement, and it needs recording for the same reason: a row that leaves
     * the worklist and returns has had two decisions made about it, and the second one
     * explains the first. Recorded as `unretire` rather than as an absence of `retire`,
     * because a thread that says only "retired" leaves a reader wondering whether the
     * phrase is still off the list.
     *
     * Note this is **not** the usual way a phrase comes back. The usual way is a render:
     * discovery clears `retired_on` on its own when a page misses on the string, and that
     * path writes no history at all — the retirement was simply wrong and the site said so.
     * A row that reappears with no `unretire` entry is that case, and it is the signal
     * worth acting on: something still renders the phrase.
     *
     * @param int $id
     * @param string|null $reason
     * @return bool whether a retired row of this project was found and brought back
     */
    public function unretirePhraseById($id, $reason = null)
    {
        return $this->setRetirementById((int) $id, false, $reason);
    }

    /**
     * @param int $id
     * @param bool $retire true to retire, false to un-retire
     * @param string|null $reason
     * @return bool whether a row in the opposite state was found
     */
    protected function setRetirementById($id, $retire, $reason = null)
    {
        $sql = sprintf(
            'SELECT `translation_phrase_id`, `project`, `text_domain`, `phrase_hash` FROM `%s` '
            . 'WHERE `project` = ? AND `translation_phrase_id` = ? AND `retired_on` IS %s',
            $this->config['phrases_table_name'],
            $retire ? 'NULL' : 'NOT NULL'
        );
        $row = $this->adapter->query($sql, [$this->config['project_name'], (int) $id])->current();
        if (null === $row) {
            return false;
        }
        $row = (array) $row;

        $sql = sprintf(
            'UPDATE `%s` SET `retired_on` = %s WHERE `translation_phrase_id` = ?',
            $this->config['phrases_table_name'],
            $retire ? 'UTC_TIMESTAMP()' : 'NULL'
        );
        $this->adapter->query($sql, [$row['translation_phrase_id']]);

        $this->recordPhraseEvent(
            $row,
            $retire ? self::OPERATION_RETIRE : self::OPERATION_UNRETIRE,
            $reason
        );

        //The listing, the API collection and the compiled catalogs all read a cached view.
        $this->invalidatePhraseCaches();

        return true;
    }

    /**
     * Note a retirement or un-retirement in the history, so the thread says why a phrase
     * left the worklist or came back to it.
     *
     * Neither destroys anything, which is why they do not fit the shape of every
     * other row here — there is no previous text and no language. They are recorded anyway,
     * because the question it answers is one somebody will certainly ask: a phrase with
     * four good translations vanishes from `/admin/translations` and the only honest
     * answer to "what happened to it" was, until now, "something retired it, and nothing
     * wrote down what or why".
     *
     * So the row is deliberately shaped as an *event*, not a loss:
     *
     * - `operation` is `retire` or `unretire`, which is what a reader has to branch on. The
     *   API and the GUI both render either as a line of narrative rather than as
     *   recoverable text. Anything that reads `old_translation` on one of these rows is
     *   reading a field that is empty by design.
     * - `locale` is `''` — the empty string, meaning "the phrase, not a language".
     *   Retirement is not language-specific, and `''` is not a locale any translation row
     *   can hold, so it cannot collide with one. A language-filtered read includes these
     *   for the same reason: an event about the whole phrase belongs in every language's
     *   thread.
     * - `old_translation` is `''`. Nothing was destroyed, and putting the phrase text
     *   there would read as a translation that had been.
     *
     * Silent when the history table is absent, like every other write to it: an
     * installation that has not run M006 must still be able to retire a phrase.
     *
     * @param array<string, mixed> $phraseRow project, text_domain, phrase_hash and id
     * @param string $operation self::OPERATION_RETIRE or self::OPERATION_UNRETIRE
     * @param string|null $reason why, from the caller
     */
    protected function recordPhraseEvent(array $phraseRow, $operation = self::OPERATION_RETIRE, $reason = null)
    {
        $history = $this->config['translations_history_table_name'] ?? 'trans_translations_history';
        if (! $this->hasHistoryTable()) {
            return;
        }

        $note = null === $reason || '' === $reason ? null : mb_substr((string) $reason, 0, self::NOTE_LENGTH);

        $sql = sprintf(
            'INSERT INTO `%s` (`project`, `phrase_hash`, `locale`, `text_domain`, '
            . '`translation_phrase_id`, `old_translation`, `operation`, `notes`, `replaced_by`, `replaced_on`) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $history
        );
        $this->adapter->query($sql, [
            $phraseRow['project'],
            $phraseRow['phrase_hash'],
            self::PHRASE_EVENT_LOCALE,
            $phraseRow['text_domain'],
            $phraseRow['translation_phrase_id'],
            '',
            $operation,
            $note,
            $this->getActingUserId(),
            date_format(new \DateTime('now', new \DateTimeZone('UTC')), 'Y-m-d H:i:s'),
        ]);
    }

    /** Resolved once per request; see recordTranslationHistory() for why it is asked at all. */
    private function hasHistoryTable()
    {
        $history = $this->config['translations_history_table_name'] ?? 'trans_translations_history';
        if (! isset($this->historyTableExists)) {
            $this->historyTableExists = (bool) $this->adapter->query('SHOW TABLES LIKE ?', [$history])->count();
        }

        return $this->historyTableExists;
    }

    /**
     * Whether this project has a phrase with this id.
     *
     * **Project-scoped, and that is not cosmetic.** This was the one path in this class
     * that fetched by bare id, and deletePhrase() is its only caller: an admin of one
     * application could delete another application's phrase, and its translations with
     * it, by putting an integer in the URL. Three projects share this table here, and
     * the route constraint on `phrase_id` covers the whole live id range. Every read
     * path is scoped for the reasons set out on applyPhraseCriteria(); a *destructive*
     * path had a stronger claim to it than any of them.
     *
     * @param int|string $id
     * @throws \Exception
     * @return boolean
     */
    public function existsPhrase($id)
    {
        $gateway = $this->phrasesGateway;
        $result  = $gateway->select([
            'translation_phrase_id' => (int) $id,
            'project'               => $this->config['project_name'],
        ]);
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

        //Scoped again on the delete itself rather than trusting the check above. The
        //two statements are not in a transaction, so a check-then-delete is not
        //atomic, and the predicate costs nothing.
        $gateway = $this->phrasesGateway;
        $return = $gateway->delete([
            'translation_phrase_id' => (int) $id,
            'project'               => $this->config['project_name'],
        ]);

        if ($return !== 1) {
            throw new \Exception('Delete action expected a return code of \'1\', received \'' . $return . '\'');
        }

        if ($refreshCache) {
            $this->invalidatePhraseCaches();
        }

        return $return;
    }

    /**
     * Mark phrases as no longer part of the project, without destroying anything.
     *
     * ## Why this exists instead of a DELETE
     *
     * Nothing was ever removed from these tables, and the reason was never that nobody
     * wanted to: `deletePhrase()` cascades the translations away and
     * `trans_translations` has no history to recover them from, so a wrong deletion
     * silently destroys human work that cannot be got back. Against that, doing nothing
     * is always the rational choice, and the table only grows.
     *
     * Retirement inverts the economics. The row and its translations stay; the phrase
     * simply stops appearing in the translator's listing and in the `/api/v3/phrases`
     * collection, so nobody is asked to work on it. And it is self-repairing: the first
     * time a page renders a retired phrase, {@see reportMissingTranslation()} sees it in
     * the index flagged as retired and queues it, and the idempotent insert in
     * {@see writeMissingPhrasesToDb()} clears `retired_on`. A wrong retirement costs
     * nothing and fixes itself; a wrong deletion costs translations nobody can recover.
     * That is the difference between a decision that has to be right and one that only
     * has to be roughly right.
     *
     * It is what makes bulk cleanup safe at all. `origin_route` records where a phrase
     * was *first seen*, not where it is used — the index is keyed by phrase text, so
     * whichever page rendered a string first owns its origin route permanently. So
     * retiring everything from a route that has been removed will always catch live
     * strings that merely had the bad luck to appear there first. With retirement,
     * those come back on their own. With deletion, each one is a judgement call made
     * under threat of unrecoverable loss.
     *
     * ## Two limits on "comes back on its own", both real
     *
     * **A phrase already translated in every configured locale will not wake itself.**
     * The return path hangs off `Translator::EVENT_MISSING_TRANSLATION`, which fires
     * only when the compiled catalog for the rendered locale has no entry — that is
     * the whole point of the architecture, and putting a write on the path of a
     * *successful* lookup is the `last_seen` column this design deliberately does not
     * have. So retirement is reliable exactly where it matters, on phrases with
     * outstanding work, and a fully translated phrase that is still in use stays
     * retired until somebody calls {@see unretire()}. It keeps rendering either way.
     *
     * **Retiring from the console does not reach the web server's cache.** APCu's
     * segment belongs to the SAPI that created it, so clearing it from a CLI process
     * leaves the Apache workers holding a phrase index that still says these rows are
     * live — and a render that consults a stale index queues no un-retire. Nothing is
     * corrupted and it self-corrects when the item expires, but until then the return
     * path is simply not armed. Clear the web cache after a bulk retirement.
     *
     * ## This is the blunt one; two others are sharper
     *
     * {@see retirePhraseById()} retires one row **and records why** — the surface for a
     * judgement about a single phrase, which is what an agent working through a worklist
     * makes. {@see retirePhraseByText()} is for content the application knows has been
     * superseded. This method is for bulk cleanup after a removed feature, and it is the
     * only one of the three that can write no history: it takes ids and no reason. The
     * console command loops the per-id method precisely so that a bulk retirement is still
     * explicable afterwards.
     *
     * @param int[] $ids
     * @return int rows affected
     */
    public function retire(array $ids)
    {
        return $this->setRetirement($ids, date_format(new \DateTime('now', new \DateTimeZone('UTC')), 'Y-m-d H:i:s'));
    }

    /**
     * Bring retired phrases back by hand, rather than waiting for a render to do it.
     *
     * @param int[] $ids
     * @return int rows affected
     */
    public function unretire(array $ids)
    {
        return $this->setRetirement($ids, null);
    }

    /**
     * @param int[] $ids
     * @param string|null $retiredOn
     * @return int rows affected
     */
    protected function setRetirement(array $ids, $retiredOn)
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ([] === $ids) {
            return 0;
        }

        $sql    = new Sql($this->adapter);
        $update = $sql->update($this->config['phrases_table_name'])
            ->set(['retired_on' => $retiredOn])
            //project-scoped for the same reason deletePhrase() is: an id alone
            //addresses a table three applications share
            ->where([
                'translation_phrase_id' => $ids,
                'project'               => $this->config['project_name'],
            ]);
        $affected = $sql->prepareStatementForSqlObject($update)->execute()->getAffectedRows();

        $this->invalidatePhraseCaches();

        return (int) $affected;
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
     * Which phrases this project already has, per text domain, and which are retired.
     *
     * The value is **`true` when the row is retired** and `false` when it is live, so
     * this answers two questions at once: `isset()` is the membership test the render
     * path used to make, and the value tells it whether a row that does exist needs
     * waking up. A plain membership set could not express the second, and getting that
     * wrong is subtle: if retired rows were simply absent from the set, the render path
     * would insert a *second* row for a phrase that already has translations; if they
     * were present as bare members, nothing would ever un-retire and `retired_on` would
     * be a one-way door. See {@see retire()}.
     *
     * ## Why hashes rather than the phrases
     *
     * Because the hash *is* the identity — see {@see PhraseIdentity} — and because the
     * query then reads 32 bytes a row instead of the phrase. The size of this item
     * therefore no longer depends on how long the phrases are, which is what made it
     * uncacheable before: this table held 5,088 rows of truncated page bodies, and
     * holding the phrases themselves produced a 9.9 MiB array that blew past the
     * cache's item budget, was silently never stored, and so ran its query on every
     * single request. (Against production's 32 MB APCu segment, an item that size is
     * also the exact shape of the allocation failure that wipes the whole segment.)
     * Those rows are gone and the corpus is now 75 KB, but the property worth keeping
     * is that this item's size is bounded by the row *count* alone.
     *
     * ## Why the hashing is still not done in SQL
     *
     * `SELECT SHA2(phrase, 256)` is charset-dependent: the server hashes the value's
     * bytes in the column's character set, so a later charset conversion silently
     * changes every hash while PHP keeps computing the old one. Reading the stored
     * `phrase_hash` column is not the same thing — that column was written from PHP by
     * this class, so it carries no dependency on how the server would have hashed it.
     * The one exception is M003's one-time backfill, which is argued there.
     *
     * ## This is now an optimization, not a correctness mechanism
     *
     * `UNIQUE (project, text_domain, phrase_hash)` and the `ON DUPLICATE KEY UPDATE` in
     * {@see writeMissingPhrasesToDb()} mean a stale, evicted or entirely absent cache
     * can no longer cause a duplicate row. It can only cause a redundant insert
     * attempt. That was not true before, and the difference is the whole point of the
     * constraint.
     *
     * @return array<string, array<string, bool>> text domain => hex phrase hash => is retired
     */
    public function getPhraseIndex()
    {
        if (null !== ($cached = $this->cache->get(PhraseCache::KEY_PHRASE_INDEX))) {
            return $cached;
        }

        //Executed through prepareStatementForSqlObject(), the way every other read in
        //this class does it, and NOT through fetchSome($select).
        //
        //That spelling was here for years and did nothing. fetchSome() passes its
        //first argument to TableGateway::select() as a *predicate*, and a
        //Laminas\Db\Sql\Sql object is not one — the gateway silently ignored it and
        //ran `SELECT * FROM trans_phrases` with no WHERE at all. So neither the column
        //list nor the project filter was ever applied, and this method has always
        //returned every phrase of **every project sharing the table**, keyed only by
        //text domain. Text domains overlap between projects, so a phrase belonging to
        //another application suppressed the insert of this project's own copy, and
        //that phrase stayed permanently untranslatable here. It also explains the size
        //this item used to reach: the measurement was across all projects.
        $sql    = new Sql($this->adapter);
        $select = $sql->select($this->config['phrases_table_name'])
            ->columns([
                'text_domain',
                //hex here rather than bin2hex() in PHP so nothing binary crosses the
                //driver; the column is BINARY(32) and some drivers hand back raw
                //bytes in ways that do not survive a JSON-serializing cache.
                'hex_hash' => new \Laminas\Db\Sql\Expression('LOWER(HEX(`phrase_hash`))'),
                'retired_on',
            ])
            ->where(['project' => $this->config['project_name']]);

        $return = [];
        foreach ($sql->prepareStatementForSqlObject($select)->execute() as $row) {
            $return[$row['text_domain']][(string) $row['hex_hash']] = null !== $row['retired_on'];
        }

        $this->cache->set(PhraseCache::KEY_PHRASE_INDEX, $return);
        return $return;
    }

    /**
     * Queue a phrase for insertion, and stop asking about it this request.
     *
     * Marking it seen — and seen as *live* — is what keeps a phrase that appears twice
     * on one page from being queued twice, and what keeps a phrase queued for
     * un-retirement from also being queued for insertion.
     *
     * @param array $params
     */
    protected function addMissingPhrase($params)
    {
        $this->phraseIndex[$params['text_domain']][PhraseIdentity::hex($params['message'])] = false;
        $this->newMissingPhrases[$params['text_domain']][] = $params['message'];
        return $this;
    }

    /**
     * Note a phrase the translator asked for, and act on what the database knows.
     *
     * Three outcomes, and the middle one is the reason the index holds a flag rather
     * than a bare membership marker:
     *
     * - **absent** — queue an insert
     * - **present but retired** — queue it anyway. The insert is idempotent and its
     *   `ON DUPLICATE KEY UPDATE` clears `retired_on`, so a phrase that turns out to
     *   still be in use wakes itself up the first time a page renders it, keeping the
     *   translations it already had. That reversibility is what makes retiring a
     *   phrase a cheap decision instead of an irreversible one.
     * - **present and live** — nothing to do, which is the overwhelmingly common case
     *   and must stay free.
     *
     * Called from the translator's missing-translation event, so this runs inside page
     * rendering: the index is loaded once per request and the test is an isset() on a
     * hash, never a query and never a scan.
     *
     * @param array $params
     */
    public function reportMissingTranslation($params)
    {
        if (! $this->phraseIndexLoaded) {
            $this->phraseIndex       = $this->getPhraseIndex();
            $this->phraseIndexLoaded = true;
        }
        $hex   = PhraseIdentity::hex($params['message']);
        $state = $this->phraseIndex[$params['text_domain']][$hex] ?? null;
        if (null === $state || true === $state) {
            $this->addMissingPhrase($params);
        }
        return $this;
    }

    /**
     * Returns the translated text of the db in a 4-dimensional array
     *
     * **Retired phrases are included, deliberately.** This is what
     * writePhpTranslationArrays() compiles into the `*.lang.php` catalogs the site
     * actually renders from, and retiring a phrase is a statement about the
     * *translator's worklist*, not about what the site displays. Excluding them here
     * would mean that retiring a phrase instantly reverts every page still rendering it
     * to English — which is precisely the irreversible damage `retired_on` exists to
     * avoid, arriving by a different door. A retired phrase keeps rendering its
     * translation until a render proves it live again or a human deletes the row.
     *
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
            //A phrase is stored with LF line endings, because that is the form its hash
            //is taken over — see PhraseIdentity::normalize(). A template whose file has
            //CRLF endings hands the translator the CRLF spelling, and laminas' catalog
            //lookup is byte-exact, so without a second key that render would fall back
            //to its source text and never be recorded as missing either: the identity
            //that made it not-a-new-phrase is the same one that makes it not-found.
            //One extra key per multi-line phrase, 42 of them across three projects,
            //buys the guarantee that normalizing cannot make anything untranslatable.
            foreach (self::catalogKeys((string) $tran['phrase']) as $key) {
                $return[$tran['text_domain']][$tran['locale']][$key] = $tran['translation'];
            }
        }
        $this->cache->set(PhraseCache::KEY_TRANSLATED_TEXT, $return);
        return $return;
    }

    /**
     * Every spelling of a stored phrase that a template might hand the translator.
     *
     * The stored form always, and its CRLF spelling when it has line endings at all.
     * Single-line phrases — the overwhelming majority — get one key and cost nothing.
     *
     * @return list<string> the stored form first, so a one-key phrase is unchanged
     */
    private static function catalogKeys(string $phrase): array
    {
        if (! str_contains($phrase, "\n")) {
            return [$phrase];
        }

        return [$phrase, str_replace("\n", "\r\n", $phrase)];
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

        //Resolved once, here, and deliberately *before* the first INSERT.
        //
        //This used to be called from inside the loop, twice, which made a throwing
        //provider a mid-write failure rather than a pre-write one. In this application
        //the configured provider reaches for the host's session, so in a console
        //process it does not merely return null — building it raises. The phrase row
        //had already been inserted by then, the exception escaped before its key-locale
        //translation row was, and because the phrase index reports the phrase *present*
        //no later render ever completed it: a permanently untranslatable row, created by
        //a failure that looked like it had done nothing. Observed 2026-08-10.
        //
        //Hoisting it is the cheap half of the fix and it is the half that generalises:
        //whatever the provider does, it now happens while there is nothing to leave
        //half-written. The transaction below covers everything else.
        $actingUserId = $this->getActingUserId();

        $localesToSearch = $this->config['locales_to_translate'];
        if (! in_array($this->config['key_locale'], $localesToSearch)) {
            $localesToSearch[] = $this->config['key_locale'];
        }

        //if we find something, we'll have to write the php arrays
        $weFoundAPreviousMatch = false;
        $result = [];
        $connection = $this->adapter->getDriver()->getConnection();
        foreach ($this->newMissingPhrases as $textDomain => $phrases) {
            foreach ($phrases as $phrase) {
                if (! isset($phrase)) {
                    continue;
                }
                //One transaction per phrase, not one for the whole flush.
                //
                //A phrase row and its key-locale translation are a unit: the row alone
                //is worse than nothing, because the phrase index will report it present
                //and nothing will ever finish it. Per-phrase rather than per-flush so
                //that one unwritable phrase does not discard the others that were
                //discovered in the same request.
                //
                //laminas-db counts nested transactions, so this is safe when the caller
                //already opened one — a nested begin/commit pair only adjusts the
                //counter. A nested *rollback* does discard the outer transaction too,
                //which is heavy-handed but never unsafe: the alternative is committing a
                //half-written phrase.
                $connection->beginTransaction();
                try {
                    //Idempotent, and the un-retire branch in one statement.
                    //
                    //Written by hand because laminas-db's Sql\Insert cannot express ON
                    //DUPLICATE KEY UPDATE, and this clause is doing three separate jobs:
                    //
                    //- it makes a stale or evicted phrase index harmless. Before the
                    //  UNIQUE constraint existed, a cache miss on a phrase that was in
                    //  fact present inserted a second row, and nothing anywhere refused
                    //  it. That is now a no-op instead of a duplicate.
                    //- it clears `retired_on`, which is how a phrase that was retired and
                    //  turns out to still be in use comes back with its translations
                    //  intact. See reportMissingTranslation().
                    //- `LAST_INSERT_ID(translation_phrase_id)` is what makes
                    //  getGeneratedValue() answer with the *existing* row's id on the
                    //  duplicate branch. Without it MySQL reports 0 there, and the
                    //  translation rows below would be attached to phrase 0.
                    //
                    //`origin_route` is deliberately not updated: it records where a phrase
                    //was first seen, and overwriting it on every subsequent sighting would
                    //turn the only context a translator gets into "wherever it was
                    //rendered most recently", which is both less useful and less true.
                    $sql = sprintf(
                        'INSERT INTO `%s` (`project`, `text_domain`, `phrase`, `phrase_hash`, `added_on`, '
                        . '`origin_route`) VALUES (?, ?, ?, ?, ?, ?) '
                        . 'ON DUPLICATE KEY UPDATE `retired_on` = NULL, '
                        . '`translation_phrase_id` = LAST_INSERT_ID(`translation_phrase_id`)',
                        $this->config['phrases_table_name']
                    );
                    //The normalized form is stored, not the discovered one. The hash is
                    //computed over the normalized form, and a row whose `phrase` bytes
                    //disagreed with what its own hash was taken over is a row nothing
                    //can ever look up again. See PhraseIdentity::normalize(); the only
                    //difference is CRLF, and the catalog answers both spellings.
                    $lastResult = $this->adapter->query($sql, [
                        $this->config['project_name'],
                        $textDomain,
                        PhraseIdentity::normalize($phrase),
                        PhraseIdentity::raw($phrase),
                        $dateString,
                        $routeName,
                    ]);
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
                                                $actingUserId,
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
                            'modified_by' => $actingUserId,
                            'modified_on' => $dateString,
                        ];
                    }

                    //insert rows
                    //
                    //The no-op ON DUPLICATE clause exists for the un-retire branch above:
                    //a phrase coming back from retirement already has its translations,
                    //and `UNIQUE (translation_phrase_id, locale)` would otherwise make
                    //this statement throw. Assigning a column to itself is the spelling
                    //that means "leave the stored row exactly as it is" — which is the
                    //required behaviour, because the stored row is a translator's work and
                    //the value computed here is a guess derived from another text domain.
                    foreach ($translationsToInsert as $row) {
                        $sql = sprintf(
                            'INSERT INTO `%s` (`translation_phrase_id`, `locale`, `translation`, `modified_by`, '
                            . '`modified_on`) VALUES (?, ?, ?, ?, ?) '
                            . 'ON DUPLICATE KEY UPDATE `translation` = `translation`',
                            $this->config['translations_table_name']
                        );
                        $result[] = $this->adapter->query($sql, [
                            $row['translation_phrase_id'],
                            $row['locale'],
                            $row['translation'],
                            $row['modified_by'],
                            $row['modified_on'],
                        ]);
                    }
                    $connection->commit();
                } catch (\Throwable $e) {
                    //Rolled back and rethrown, not swallowed. A phrase that cannot be
                    //recorded is worth knowing about — the caller decides whether it is
                    //fatal, and flush()'s laminas caller already treats an exception
                    //here as a page failure. What this guarantees is only that the
                    //database is left as it was, so the next request can try again.
                    $connection->rollback();
                    throw $e;
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
        //$where is handed to TableGateway::select() as a predicate. A Sql or Select
        //object is not one, and the gateway does not complain — it ignores the
        //argument and returns the entire table. getPhraseIndex() did exactly that for
        //years, unscoped and unfiltered, and nothing failed loudly enough to notice.
        //Refusing it here is what makes the next occurrence a stack trace instead of a
        //quiet cross-project data leak.
        if (isset($where) && ($where instanceof Sql || $where instanceof \Laminas\Db\Sql\Select)) {
            throw new \InvalidArgumentException(
                'fetchSome() takes a predicate, not a Sql or Select object. Build the Select and run it '
                . 'with Sql::prepareStatementForSqlObject()->execute(); passing it here silently returns '
                . 'the whole table.'
            );
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
