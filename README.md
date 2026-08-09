# JTranslate

Database-backed translation for Laminas MVC, with a web GUI for translators and
catalogs precompiled to PHP arrays.

Phrases are discovered at runtime rather than extracted from source, which is the
point of the design: most of what this translates is *data* — book titles,
dictionary entries, shrine names — and no source scanner can find those. A page
renders, the translator reports a phrase it has no translation for, the phrase
lands in the database, a human translates it in the GUI, and the catalog is
recompiled to a PHP array that the next request reads essentially for free.

## Status: 2.0 is a transitional release

**2.0 is not the shape this library is meant to end up in.** It exists to break
the couplings that made the 1.x line unusable outside one specific application,
so that the reduced-scope 3.0 can be built without a rewrite. Expect 3.0 to
remove roughly a third of what is still here — see
[What is leaving](#what-is-leaving-in-30).

Two production applications share this code. Treat every removal below as
scheduled, not hypothetical.

## Why the PHP-array format, and why it is staying

The catalogs are plain `return [...]` PHP files, and that is not legacy — it is
the fastest option available by a very wide margin, because OPcache interns a
literal array as an immutable shared-memory array and `return [...]` hands back a
pointer rather than parsing or copying anything.

Measured on a 698-message, 64 KB catalog, PHP 8.5, 300 iterations:

| loader | OPcache on | OPcache off |
| --- | --- | --- |
| `include` php array | **0.1 µs** | 352 µs |
| `Laminas\I18n` PhpArray loader | 4.3 µs | 340 µs |
| `json_decode` | 360 µs | 337 µs |
| `unserialize` | 76 µs | 83 µs |
| `apcu_fetch` | 63 µs | 71 µs |
| gettext `.mo` | 3,297 µs | 3,540 µs |

550× faster than APCu, 3,000× faster than JSON, 29,000× faster than gettext.
Nothing else can compete, because every alternative has to materialise the array
in request memory.

**The entire advantage depends on OPcache.** With it off, this format is the
slowest file-based option and five times worse than APCu. If you deploy this
somewhere without OPcache, you have chosen the wrong format.

`symfony/translation` compiles its catalogues to the same thing, which is worth
knowing when planning 3.0: the format is not a thing to migrate away from.

## Requirements

PHP 8.1+, ext-intl, Laminas MVC 3.x, and a MySQL-compatible database. The
dependency list in `composer.json` is the set of namespaces the code actually
imports — nothing aspirational.

## Installation

1. `composer require jroedel/laminas-jtranslate`
2. Copy `config/jtranslate.global.php.dist` into your application's config
   directory and set at least `project_name`.
3. Create the two tables from `config/database.sql.dist`.
4. Add `JTranslate` to `modules.config.php`.
5. Restrict the `jtranslate` route and its children to administrators. The GUI
   writes to the phrase table and to the filesystem; it is not a public page.

## Configuration

Everything lives under the `jtranslate` key.

| key | meaning |
| --- | --- |
| `project_name` | **Required.** Several applications may share one phrase table; this string is what separates them. |
| `phrases_table_name` / `translations_table_name` | default `trans_phrases` / `trans_translations` |
| `key_locale` | the locale the phrase itself is written in, default `en_US` |
| `locales_to_translate` | the locales the GUI offers. Merged additively by Laminas' config merger, so a numeric-keyed list in application config **appends to** the module's defaults rather than replacing them. |
| `root_directory` | where compiled catalogs are written, default `getcwd()` |
| `cache_options` | a Laminas cache storage configuration |
| `cache_service` | alternatively, the service id of an existing PSR-16 cache. This is the seam a non-Laminas host uses. |
| `max_cache_item_bytes` | default 2 MiB — see [Caching](#caching) |

## How it works

1. At bootstrap the translator is given a file pattern for every loaded module's
   `language/` directory, plus one per subdirectory of the project's `language/`.
2. On dispatch the text domain is set to the controller's root namespace, so a
   module's translations stay with the module.
3. A missing translation raises the translator's event;
   `TranslationsTable::reportMissingTranslation()` records it in memory.
4. At the end of the request `flush()` writes the new phrases to the database.
5. When a translator saves a phrase in the GUI, the affected catalogs are
   recompiled.

**Never hand-edit a compiled catalog.** It is regenerated from the database and
your change will disappear. Edit the database, or use the GUI.

### Caching

Two derived arrays are cached, through `JTranslate\Cache\PhraseCache` over PSR-16:
the per-domain set of known phrase hashes, and the compiled text tree.

`max_cache_item_bytes` is a real safety mechanism, not a tuning knob. An
`apcu_store()` that cannot fit does not fail politely — under `apc.ttl=0` a failed
allocation clears the **entire** segment, evicting everything every other part of
the application cached. Raising this limit above what your segment can absorb is
how you take a site down.

Cache errors never propagate. A full or misconfigured cache makes this library
slow, never broken.

### Filesystem behaviour

Catalogs are written to a temporary file in the target directory and moved into
place with `rename()`. This matters for two reasons: the files are `include`d by
concurrent requests, so an in-place write lets another process compile a
half-written file; and `rename()` takes its permission from the *directory*, so a
catalog left behind by a deploy running as a different user is still replaceable.
`opcache_invalidate()` follows the rename.

Failures raise. `writePhpTranslationArrays()` used to discard every return value,
so an unwritable directory meant the database was updated, the GUI said
"Translations successfully updated", and the site kept serving the old text with
nothing logged. If you are debugging what looks like a permissions problem, check
that the web server user can write the target directory — the exception names it.

## Extending it

The model has no framework in its constructor. `TranslationsTable` takes two table
gateways, a `PhraseCache`, config, an optional
`JTranslate\Service\ActingUserProviderInterface`, an optional
`JTranslate\Service\UserDirectoryInterface`, and a root directory. Nothing else.

That means you can build one from a Symfony request, a console command, or a
test, and call `flush($routeName)` yourself at whatever point your framework
considers the end of a request. `TranslationsTableFactory` is the Laminas adapter
and is the only file that knows about `MvcEvent`.

The two collaborator interfaces are resolved reflectively by service id behind a
`has()` check, and wrapped in callable adapters, so a host that provides neither
still boots — you lose `modified_by` stamping and the attribution column in the
admin listing, nothing else.

## What is leaving in 3.0

None of the following is deprecated at the language level yet — 2.0 breaks enough
already, and both consuming applications still call into some of it. It is listed
so nobody builds anything new on it.

### Not translation, and never was

| what | lines | why it is going |
| --- | --- | --- |
| `Controller\Plugin\NowMessenger`, `View\Helper\NowMessenger`, its factory | ~454 | A `FlashMessenger` clone that renders in the *same* request. It has nothing to do with translation; it lives here for historical reasons. Under Twig this is a template variable. It currently has callers in SionModel and two other modules, so it must move or be replaced there first. |
| `Model\CountriesInfo`, `data/countries.json`, `View\Helper\CountryName`, `View\Helper\Flag`, their factories | ~380 | Country names and flags. `symfony/intl` and ext-intl cover the names; flags are CSS or emoji. Also has external callers today. |

### Superseded by the platform

| what | lines | replacement |
| --- | --- | --- |
| `TranslationsTable::getLocaleNames()` | **440** | A hardcoded CLDR locale-name table, circa 2012, and 32% of that file. `\Locale::getDisplayName()` does this natively, correctly, and localised into the viewer's language. `View\Helper\LanguageName` already made this switch in 2.0; the table itself is next. |
| `Controller\LazyControllerFactory` | 75 | `Laminas\ServiceManager\AbstractFactory\ReflectionBasedAbstractFactory`, which did not exist when this was written. |
| `TranslationsTable::fetchSome()`, `setDbAdapter()`, `AdapterAwareInterface` | — | A homegrown query helper and a laminas-db pattern that predates the current one. |
| `$arrayFilePatterns` | — | Vestigial. Declared, never read. |

### Changing shape

| what | why |
| --- | --- |
| Catalogs written into the source tree (`module/*/language/`, `language/`) | These are **build artifacts stored among sources**. They are inconsistently versioned — some tracked in git and therefore overwritten by every deploy, some gitignored and therefore unbackupable — and there is no command that regenerates them, so the only way to rebuild is for a human to click Save on a phrase. 3.0 moves them to a cache directory and adds a console command. This is also the real fix for the permissions complaints: nothing the web server must write should live in deployed source. |
| The admin GUI (`JTranslateController`, both forms, three `.phtml`) | ~480 lines built on `AbstractActionController`, laminas-form and `FlashMessenger`. It is the last part that requires laminas-mvc, and it is the part most worth rewriting rather than porting. |
| `Module::onBootstrap()`'s per-controller text-domain listener | Sets the text domain from the controller's root namespace on every dispatch, eagerly instantiating twelve view helpers to do it. Under a framework where the domain is an argument to `trans()`, this disappears. |
| `getTranslations()` loading the full user directory | It fetches every user to populate one attribution column, for every caller including those that never display it. |

## Upgrading

See [UPGRADE.md](UPGRADE.md) for the 1.x → 2.0 breaking changes. There are six,
all mechanical.

## Development

```
vendor/bin/phpcs        # PSR-12 plus house sniffs; exits 0
```

The ruleset checks `src` and `config`. `language/` is excluded because those are
compiled catalogs whose line length is the length of a translated phrase — a
line-length rule there could never be satisfied and would fail on the next export.

This repository has no test suite of its own; it is verified from the applications
that consume it. That is a gap, and closing it belongs with 3.0.

## License

MIT.
