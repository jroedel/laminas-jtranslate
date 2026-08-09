# Upgrading

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
