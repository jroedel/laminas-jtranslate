# Upgrading

## 2.1 → 2.2

Behaviour fixes. Nothing here needs a migration, but three of them change what the
library *does* and one of those changes what a save can destroy — read 2 and 3 before
upgrading.

### 1. An English page view now discovers phrases

`TranslatorEventListener` must be constructed with `getLocales(**true**)`. Both
wiring sites in a host application need it:

```diff
-new TranslatorEventListener($table, $table->getLocales())
+new TranslatorEventListener($table, $table->getLocales(true))
```

`getLocales()` omits the **key** locale, so a miss in it was ignored — and since the
listener is the only path by which a phrase ever enters `trans_phrases`, a string that
appeared only on key-locale pages was never recorded and therefore never translatable.
The "a deleted phrase comes back the next time a page renders it" property held only
for visitors browsing in a *translated* locale.

Steady-state cost is nil: every phrase in the database has an auto-inserted key-locale
translation, so the compiled catalog already has it and no event fires. Expect a bounded
one-off burst of inserts after upgrading — the strings that were being dropped — then
silence.

### 2. `updatePhrase()` distinguishes "leave alone" from "retract"

| submitted | before | now |
| --- | --- | --- |
| absent | leave alone | leave alone |
| `''` | leave alone | leave alone |
| `'0'` | **leave alone** | **written** |
| `null` | leave alone | **row deleted** |

`''` still means "leave this locale alone", and that is not negotiable: the web form
renders every locale as a textarea on every edit, so an untouched form posts `''` for
every language the translator did not fill in. If `''` meant "clear", saving one language
would wipe the others.

**If you add a `ToNull` filter to a translation input, you will delete translations.**

And if what you actually want is for a phrase to stop appearing on the worklist, this is
the wrong tool — see §4 on retirement.
`EditPhraseForm` deliberately has only `StringTrim`, which is what keeps `''` and `null`
apart. A host application with its own form must do the same.

`'0'` was silently unsaveable because it is falsy in PHP. Any caller that mirrored that
quirk — filtering falsy values before deciding what changed — should now filter only `''`.

### 3. Discovery is transactional, and the acting user is resolved first

`writeMissingPhrasesToDb()` wraps each phrase and its key-locale translation in one
transaction, and asks for the acting user id *before* the first insert.

The failure this closes was partial rather than clean: the phrase row was inserted, the
acting-user lookup raised, and the exception escaped before the translation row was
written. Because `getPhraseIndex()` then reported the phrase *present*, no later render
ever completed it — a permanently untranslatable row produced by a failure that looked
like it had done nothing.

laminas-db counts nested transactions, so a caller that already opened one is fine. Note
that a nested *rollback* discards the outer transaction too; that is laminas-db's
behaviour, not a choice made here.

A host application should also make its `ActingUserProviderInterface` answer `null`
rather than raise when there is no session — see JUser's
`AuthServiceActingUserProvider`. `TranslationsTable::setActingUserId()` remains the way a
caller with a known identity and no session (an API request) attributes its writes.

### 4. Two new config keys, both defaulting to previous behaviour

| key | default | was |
| --- | --- | --- |
| `catalog_file_pattern` | `'%s.lang.php'` | hardcoded in the model |
| `navigation_text_domain` | `'Application'` | hardcoded in `Module::onBootstrap()` |

The translator's fallback locale now follows `key_locale` instead of being hardcoded to
`en_US` — correct by definition, since the key locale is the language the phrases are
written in.

### 5. `CountriesInfo` takes the locales it should answer for

```diff
-new CountriesInfo($countries)
+new CountriesInfo($countries, $locales)
```

Defaults to `['en_US']` if omitted, **not** to the five locales the method used to
hardcode — a caller that has not been updated gets English rather than translations for
languages it never configured. `CountriesFactory` reads them from `jtranslate` config.

Two bugs fell out of this. A configured locale the vendored data cannot translate now
gets a key holding the English name, instead of being absent. And Scotland — built by
cloning `GB` — no longer inherits the United Kingdom's name in every language that was
not explicitly overridden; it read "Regno Unito" in Italian.

### 6. `TranslationsTable` throws without `project_name`

It always required one; every read and write scopes by it, and the table is shared
between applications, so a missing value would address another project's rows rather than
degrade. `M002` has refused to run without it since it was written.

### 7. The `jtranslate/clear-cache` route is gone

It declared `'action' => 'clearCache'` and `JTranslateController` has never had a
`clearCacheAction()`, so reaching `/admin/translations/clear-cache` was a guaranteed
dispatch failure. It carried no authorization guard either, which is the only reason
nobody hit it. Remove any guard entry naming it.

## 2.0 → 2.1

2.1 is a data-integrity release. It fixes a defect that had been silently
multiplying rows for years, and it makes the recurrence impossible in the schema
rather than in application memory.

**It requires two migrations.** Nothing here works until they are applied:

```
bin/console jtranslate:migrate --status
bin/console jtranslate:migrate            # or --pretend, where the app has no DDL rights
```

### The defect, because it explains every change below

`trans_phrases.phrase` was `varchar(2000)`. Under the non-strict `sql_mode` these
tables were created with, a longer phrase was **silently truncated on insert** —
and from that moment the row could never be recognised again, because the render
path hashes the *full* phrase and compares it against an index built from the
*stored* one. The two can never match, so every render inserted another row.

One row per pageview. On schoenstatt.link that produced 5,088 rows holding **two**
distinct strings: 74% of the project's phrase table. On a modern engine the same
phrase raises `Data too long for column 'phrase'` instead, so the bug did not go
away when MariaDB started defaulting to `STRICT_TRANS_TABLES` — it changed from
unbounded growth into an exception at end of request.

### 1. `phrase` and `translation` are `TEXT`

The truncating column is gone. There is no length limit on a phrase any more, and
no length limit on its translation, which could not fit either.

### 2. `trans_phrases` has a `phrase_hash` and a uniqueness constraint

`BINARY(32)`, a SHA-256 of the phrase's raw bytes, under
`UNIQUE (project, text_domain, phrase_hash)`.

**Why a hash and not `UNIQUE (project, text_domain, phrase(255))`.** Two reasons,
and the second is the one that is easy to miss. A prefix index truncates, and this
data already contains two distinct 517- and 772-character German phrases sharing
their first 255 characters. But more importantly, `phrase` is
`utf8mb4_unicode_520_ci` — case-insensitive, accent-insensitive, PAD SPACE — so a
`UNIQUE` index on it treats `Save`, `save` and `Save ` as one value while
`Laminas\I18n\Translator` compares catalog keys byte for byte and treats them as
three. Such an index **refuses genuinely distinct phrases**, silently and
permanently. schoenstatt.link's table holds fourteen such pairs, including
`Inglés`/`Inglês` — the Spanish and Portuguese names of English, in one text
domain.

Hash it yourself with `JTranslate\Model\PhraseIdentity`, never with `md5()` and
never with SQL's `SHA2()`. Do not normalize the phrase first; the hash must
identify the exact string the translator will use as its key.

### 3. `getPhraseIndex()` changed shape again

```diff
-['Books' => ['d8436914a5b108a17874c374cc831de9' => true, ...]]   // md5(phrase)
+['Books' => ['720ab1f0…31 more bytes as hex' => false, ...]]     // sha256(phrase) => is retired
```

The value is now a **retirement flag**, not a bare `true`. `isset()` is still the
membership test; the value tells a caller whether an existing row needs waking up.

```diff
-isset($index[$domain][md5($phrase)])
+isset($index[$domain][PhraseIdentity::hex($phrase)])
```

`PhraseCache::KEY_PHRASE_INDEX` is bumped to `jtranslate.phrase_index.2` to match.
Reusing the key would have read every v1 `true` as "this phrase is retired".

Two bugs were fixed here that a caller may have been relying on without knowing:

- The method passed a `Sql` object to `TableGateway::select()`, which takes a
  *predicate*. The gateway ignored it and ran `SELECT * FROM trans_phrases`, so
  **neither the column list nor the project filter was ever applied** and the index
  contained every project's phrases. If several applications share your table, this
  index used to leak across them — and because it gates inserts, a phrase another
  application had already recorded could never be added to yours.
- The 9.9 MiB figure that justified hashing in 2.0 was measured across all projects
  *and* across the duplicate rows. After this release the corpus is 75 KB.

### 4. `retired_on`, and what replaces `deletePhrase()`

A nullable `retired_on DATETIME`. A retired phrase disappears from the translator's
worklist and from paged reads, **keeps its translations**, and **keeps compiling
into the catalogs** so the site renders exactly what it rendered before. If a page
renders it again and the translation is missing, the idempotent insert clears
`retired_on` and it comes back intact.

New: `TranslationsTable::retire()`, `unretire()`, and a `jtranslate:retire` console
command. New criteria for `countPhrases()`/`getPhrasePage()`: `includeRetired`,
`onlyRetired`, `originRouteLike`.

**Retirement is not retraction, and the two are easy to reach for by mistake.** A
retraction (`null` through `updatePhrase()`, §2 above) deletes one language's *text* and
leaves the phrase on the worklist with one more gap. A retirement is about the *phrase*
and destroys nothing. Retracting every language to make a row go away therefore does the
opposite of retiring it, and loses every translation on the way.

Also new, for a caller that wants the judgement recorded rather than just done:
`retirePhraseById()` and `unretirePhraseById()` take a reason and write one
`trans_translations_history` row per **state change** — `operation` `retire` or
`unretire`, `locale` `''`, `old_translation` `''`, because nothing was destroyed. Calling
either on a phrase already in that state is a no-op that writes nothing, so a retry cannot
file a second judgement. `jtranslate:retire` routes through them and gained `--note`; a
consuming application can expose them (schoenstatt.link does, as
`POST /api/v3/phrases/{id}/retire`, with the note mandatory there).

Two limits, both real and both easy to expect too much of:

- A phrase already translated in **every** configured locale will not wake itself.
  The return path fires on `EVENT_MISSING_TRANSLATION`, which by design never fires
  on a successful lookup. Use `--undo`.
- Retiring from the CLI does not clear the **web server's** cache. APCu's segment
  belongs to the SAPI that created it, so the running site keeps a phrase index
  saying those rows are live and the return path is not armed until it expires.

### 5. `existsPhrase()` and `deletePhrase()` are project-scoped

They were not. `existsPhrase()` fetched by bare id and `deletePhrase()` is its only
caller, so an administrator of one application could destroy another application's
phrase — and cascade its translations away — by putting an integer in a URL. If you
were calling `existsPhrase()` for a phrase outside your configured `project_name`,
it now answers `false`.

### 6. `getTranslations()` takes a second argument

`getTranslations($fromAllProjects = false, $includeRetired = false)`. Retired
phrases are excluded by default.

### 7. `fetchSome()` refuses a `Sql` or `Select` object

It always should have. Passing one returned the whole table instead of raising, and
that is how the scoping bug in point 3 survived. It now throws.

## 1.x → 2.0

2.0 is a transitional release. Its purpose is to make the library installable and
usable on its own: the model no longer imports anything from another application,
and it no longer needs laminas-mvc to be constructed. Everything below follows
from that.

Six breaking changes, all mechanical. Nothing here changes what a translated page
renders.

### Before you start

**Clear your merged-config cache.** `Service\CacheFactory` is gone, and a cached
config that still names it produces an empty HTTP 200 on every page — a fatal with
nothing in the log if `log_errors` is off. In this application:

```
bin/console cache:clear-config
```

Elsewhere, delete whatever `module_listener_options.cache_dir` points at.

### 1. The `TranslationsTable` constructor lost its last parameter

```diff
-new TranslationsTable($phrases, $translations, $cache, $config, $actingUser, $userTable, $root, $eventManager)
+new TranslationsTable($phrases, $translations, $phraseCache, $config, $actingUser, $userDirectory, $root)
```

The model used to attach itself to `MvcEvent::EVENT_FINISH` from inside its own
constructor, which is why it could not be built without laminas-mvc — not in a
Symfony request, not in a console command, not in a test. The event wiring moved
to `TranslationsTableFactory`, at the same priority (−1).

If you build the table yourself rather than through the factory, you are now
responsible for calling `flush()` at the end of a request. If you use the factory,
nothing to do.

### 2. `finishUp(MvcEvent $e)` → `flush(?string $routeName = null)`

```diff
-$table->finishUp($mvcEvent);
+$table->flush($routeMatch?->getMatchedRouteName());
```

Same behaviour, no framework type. This one signature was most of the reason the
module was laminas-only.

### 3. `getPhraseKeysFromDb()` → `getPhraseIndex()`, with a new return shape

```diff
-['Books' => ['some phrase', 'another phrase']]
+['Books' => ['d8436914a5b108a17874c374cc831de9' => true, ...]]  // md5('some phrase')
```

It is a membership set and always was. Holding the phrases whole made the array
9.9 MiB on real data — `trans_phrases.phrase` averages 1,494 characters, because
most phrases are book and dictionary bodies rather than UI labels — so it
exceeded the cache item budget and was silently never cached, and its query ran on
every request. As md5 hashes it is about 270 KiB, it caches, and membership is an
`isset()` instead of an `in_array()` scan.

If you read this array, hash your needle: `isset($index[$domain][md5($phrase)])`.

> **Superseded by 2.1.** The shape changed again — sha256 hex keys, and the value
> is a retirement flag rather than `true`. The 9.9 MiB and 1,494-character figures
> above were both artifacts of the truncation defect 2.1 fixes: they counted 5,088
> duplicate rows holding two strings, and they were measured across every project
> sharing the table rather than one. The real corpus is 75 KB.

### 4. The cache service moved and changed type

```diff
-$container->get('JTranslate\Cache');            // Laminas\Cache\Storage\StorageInterface
+$container->get(JTranslate\Cache\PhraseCache::class);
```

`Service\CacheFactory` is replaced by `Service\PhraseCacheFactory`.
`SionModel\Db\Model\SionCacheTrait` is no longer used at all; `PhraseCache` wraps
PSR-16 and keeps the three behaviours of that trait that came from production
incidents — the item size budget, never letting a cache error become a page error,
and the in-request memory layer.

New optional config keys: `cache_service` (the service id of an existing PSR-16
cache, which is the seam for a non-Laminas host) and `max_cache_item_bytes`
(default 2 MiB).

### 5. Collaborator types are now this library's own interfaces

```diff
-SionModel\Service\ActingUserProviderInterface
+JTranslate\Service\ActingUserProviderInterface

-JUser\Model\UserTable
+JTranslate\Service\UserDirectoryInterface
```

Both are one-method contracts. The factory resolves the old service ids
reflectively behind a `has()` check and wraps whatever it finds in a callable
adapter, so **an existing SionModel/JUser application needs no change** — and an
application with neither now boots, losing only `modified_by` stamping and the
attribution column in the admin listing.

If you inject these yourself, implement the new interfaces or wrap your object in
`JTranslate\Service\Adapter\CallableActingUserProvider` /
`CallableUserDirectory`.

### 6. `View\Helper\LanguageName` uses ICU instead of SionModel

It previously called `SionModel\I18n\LanguageSupport`, a hand-maintained table
that rendered any language outside its curated list in English. It now calls
`\Locale::getDisplayLanguage()`.

Every ICU language resolves now, in any display locale, so **some names will
change** — generally from English to the correct language. The contract callers
rely on is unchanged: an unrecognised code renders as an empty string, never as
the raw code.

### 7. The compiled catalogs are no longer shipped, and the schema file is gone

`language/*.lang.php` and `config/database.sql.dist` are both deleted.

The catalogs were build artifacts stored among sources, and stale ones — 9 phrases
against the 26 in the database — because nothing could regenerate them except a human
saving a phrase in the GUI. `bin/console jtranslate:export-catalogs` rebuilds them
now, and `language/` is gitignored so they cannot drift back in.

`config/database.sql.dist` had a trailing comma after `origin_route`, so
`CREATE TABLE trans_phrases` was a syntax error and it had never been runnable as
written. Migration 001 replaces it.

**What to do on an existing installation:**

```
bin/console jtranslate:migrate --mark-applied=001-create-phrase-tables   # you have the tables
bin/console jtranslate:migrate                                          # applies 002, the seed
bin/console jtranslate:export-catalogs                                  # as the web server user
```

The seed is idempotent per phrase *and* per locale, so it fills in what your database
lacks and never overwrites a translation somebody has improved. On the database this
was developed against it added 14 phrases and touched none of the 10 already there.

If your database user lacks DDL rights — which is the normal arrangement — use
`--pretend` to get the SQL, have it run by an account that has them, then
`--mark-applied`.

### Also in 2.0, not breaking

- `writePhpTranslationArrays()` writes atomically and **raises on failure**
  instead of silently discarding every return value. If you call it, decide what
  a failure means to you: the admin action reports it to the translator, and the
  request-path caller logs it, because an exception at `EVENT_FINISH` would turn
  an unrelated page view into a 500.
- Compiled catalogs are written 0664 rather than 0775. They are data; nothing
  executes them.
- `composer.json` requires PHP `^8.1` (was `>=5.3.3`), drops `laminas/laminas-code`,
  `cakephp/utility` and `mledoze/countries`, and fixes the psr-4 path from
  `module/JTranslate/src/` to `src/`, so the package is actually installable.

### Looking ahead to 3.0

Read the "What is leaving in 3.0" table in [README.md](README.md) before building
anything new against this version. The short form: `NowMessenger`, the countries
and flag helpers, the 440-line hardcoded locale-name table, `LazyControllerFactory`,
and writing catalogs into the source tree are all scheduled for removal.
