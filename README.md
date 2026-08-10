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
3. Add `JTranslate` to `modules.config.php`.
4. Create the schema and pre-feed the GUI's own translations:
   `bin/console jtranslate:migrate`
5. Render the catalogs: `bin/console jtranslate:export-catalogs`
6. Restrict the `jtranslate` route and its children to administrators. The GUI
   writes to the phrase table and to the filesystem; it is not a public page.

Step 4 needs DDL rights, which a web application's database user often deliberately
lacks. `jtranslate:migrate --pretend` prints the SQL instead of running it, for
somebody holding an account that does; afterwards
`jtranslate:migrate --mark-applied=001-create-phrase-tables` records it.

On an installation that already has the tables, `--mark-applied` is **not** enough:
migrations 003 and 004 change the schema those tables already have, and the library
does not work correctly without them — see [Upgrading](UPGRADE.md). Mark 001 applied
and run the rest.

Note that the migrations are **not** applied in numeric order. 002 seeds data and runs
last, after the schema migrations, because it writes the `phrase_hash` column 003
creates. `MigrationRunner::MIGRATIONS` is the sequence; the numbers only identify.
One consequence: on a database predating 003, `--pretend` cannot preview 002, because
previewing does not execute the schema change it depends on. Apply 003 and 004 first,
then preview 002.

`config/database.sql.dist` is gone. It was a phpMyAdmin export carrying a trailing
comma after `origin_route`, so it had never been runnable; migration 001 replaces it.

## Commands

| command | what it does |
| --- | --- |
| `jtranslate:migrate` | apply pending migrations. `--status` reports; `--pretend` prints the SQL and changes nothing; `--mark-applied=NAME` records one as applied without running it |
| `jtranslate:export-catalogs` | rebuild every compiled `*.lang.php` from the database. `--domain=NAME` restricts it, `--dry-run` lists what it would write |
| `jtranslate:retire` | take phrases off the translator's worklist without destroying them. Select with `--id` (repeatable), `--origin-route` (a `LIKE` pattern) or `--text-domain`; `--undo` reverses it; `--dry-run` prints the selection. See [Retirement](#retirement) |

Run `jtranslate:export-catalogs` **as the user the web server runs as**, not as root.
Running it as root creates catalogs the web server cannot subsequently replace, which
is the ownership tangle that made this library look like it had a permissions bug.

## The compiled catalogs are not in this repository

They used to be: four `language/*.lang.php` files, checked in. They were build
artifacts stored among sources, and they had drifted badly — 9 phrases against the 26
in the database — because until 2.0 nothing could regenerate them except a human
saving a phrase in the GUI. They are gitignored now and produced by
`jtranslate:export-catalogs`.

What was genuinely this library's own data moved to `data/ui-phrases.php`: the phrases
its GUI displays, with the translations it shipped. Migration 002 inserts them for
your `project_name`, idempotently per phrase *and* per locale, so it fills gaps and
never overwrites a translation somebody has improved.

**`data/ui-phrases.php` may only contain strings this repository itself emits.**
`trans_phrases` is shared between the applications using this library, so a phrase in
it belongs to whichever project contributed it and may be anything at all — including
data that must not leave that project. Seeding from a database would exfiltrate one
consumer's content into every installation of the library.
`tools/verify-seed-provenance.php` enforces the rule: it collects every string literal
in `src/` and `view/` with PHP's tokenizer and requires each seeded phrase to match
one. Run it whenever you touch the seed.

## Configuration

Everything lives under the `jtranslate` key.

| key | meaning |
| --- | --- |
| `project_name` | **Required.** Several applications may share one phrase table; this string is what separates them. |
| `phrases_table_name` / `translations_table_name` | default `trans_phrases` / `trans_translations` |
| `key_locale` | the locale the phrase itself is written in, default `en_US`. Also the translator's fallback locale, and the language `CountriesInfo` falls back to |
| `catalog_file_pattern` | the compiled catalog filename, `%s` being the locale; default `%s.lang.php`. Must agree with the `translator.translation_file_patterns` pattern — this key controls the writing, that one the reading |
| `navigation_text_domain` | the text domain the navigation helper renders in, default `Application`. Unlike every other view helper the menu is *not* translated in the dispatched controller's namespace: it is one tree shown on every page, so its strings belong to whichever domain owns the menu |
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
   `TranslationsTable::reportMissingTranslation()` records it in memory —
   **in every configured locale including the key locale**, which is what makes
   discovery independent of which language a visitor happens to be browsing in. See
   `TranslatorEventListener`; a miss in an unconfigured locale is still ignored, because
   `Accept-Language` can ask for anything.
4. At the end of the request `flush()` writes the new phrases to the database, one
   transaction per phrase, so a failure can never leave a phrase row without the
   key-locale translation that makes it usable.
5. When a translator saves a phrase in the GUI, the affected catalogs are
   recompiled.

**Never hand-edit a compiled catalog.** It is regenerated from the database and
your change will disappear. Edit the database, or use the GUI.

### Reading phrases: two paths, deliberately

`getTranslations()` reads **every** phrase and translation of the project into one
array and filters in PHP. Measured on a 6,874-phrase database that is **0.220 s and
72.6 MB peak**, it memoizes nothing, and `getPhrase()` used to call it to return a
single row — so reading one phrase cost the whole table.

That is affordable once, on an admin listing that genuinely shows everything. It is not
affordable per request, and it cannot express the question a caller with a filter
actually asks, because filtering in PHP happens after the paging decision has already
been made. So there is a second path:

| method | for |
| --- | --- |
| `getTranslations($fromAllProjects)` | the admin listing, which wants all of it |
| `countPhrases($criteria)` | how many match, ignoring paging |
| `getPhrasePage($criteria, $limit, $offset)` | one page, filtered and paged in SQL |
| `getPhraseById($id)` | one phrase — 0.0007 s, and what `getPhrase()` now calls |

Criteria are named (`TranslationsTable::CRITERIA`) rather than free-form, because every
one of them ends up in a `WHERE` clause and a caller that could pass an arbitrary
column name could read across the `project` boundary.

`getPhrasePage()` runs two queries rather than one join: a phrase joined to its
translations yields one row per locale, so `LIMIT 100` over the join returns some
number of phrases between 20 and 100 — paging a joined result set silently pages the
wrong thing.

**Everything here is scoped to `project_name` in SQL.** The phrase table is shared
between projects, and a phrase is arbitrary text taken from whatever the other project
renders, so it can carry information that project's users never agreed to publish
elsewhere. `getTranslations($fromAllProjects = true)` is the one deliberate exception
and is reachable only from the admin GUI.

### What makes two phrases the same phrase

Byte identity, because that is the relation `Laminas\I18n\Translator` applies to its
catalog keys: a lookup for `Save` will never find an entry for `save`, so the two are
different phrases and need different rows. `JTranslate\Model\PhraseIdentity` is the one
implementation of it — a bare SHA-256 of the phrase's raw bytes, stored in
`trans_phrases.phrase_hash` as `BINARY(32)` under
`UNIQUE (project, text_domain, phrase_hash)`.

Nothing in MySQL expresses that relation. `utf8mb4_unicode_520_ci` is
case-insensitive, accent-insensitive and PAD SPACE, so a `UNIQUE` index on `phrase`
would refuse `Inglés` after `Inglês` — genuinely distinct phrases in one text domain,
of which schoenstatt.link's table holds fourteen pairs. And no prefix is long enough
for a `TEXT` column anyway.

Three rules, each of which reintroduces a defect if broken:

- **Never normalize before hashing.** Not trimmed, not case-folded, not NFC. The hash
  must identify the exact string the translator will use.
- **Never hash in SQL.** `SHA2()` hashes the value in the *column's* character set, so
  a later charset conversion silently changes every stored hash while PHP keeps
  computing the old one. (M003's one-time backfill is the argued exception.)
- **Never look up by hash alone.** A collision then serves the wrong translation
  instead of refusing an insert.

### Retirement

Nothing was ever removed from these tables, and the reason was never that nobody wanted
to: `deletePhrase()` cascades the translations away and `trans_translations` has no
history to recover them from, so a wrong deletion silently destroys human work. Against
that, doing nothing is always the rational choice and the table only grows.

`retired_on` inverts the economics. A retired phrase leaves the translator's worklist
and the paged reads, **keeps its translations**, and **keeps compiling into the
catalogs** — so the site renders exactly what it rendered before. If a page renders it
again and the translation is missing, the render path clears `retired_on` and the
phrase comes back intact.

That is what makes bulk cleanup safe. `origin_route` records where a phrase was *first
seen*, not where it is used, so retiring everything from a removed feature always
catches live strings that merely appeared there first. With retirement those return on
their own; with deletion each one is a judgement call made under threat of
unrecoverable loss.

Two limits worth knowing before relying on the return path:

- A phrase already translated in **every** configured locale will not wake itself. The
  path fires on `EVENT_MISSING_TRANSLATION`, which by design never fires on a
  successful lookup — putting a write on that path is the `last_seen` column this
  architecture deliberately does not have. Use `jtranslate:retire --undo`.
- Retiring from the CLI does not clear the **web server's** cache: APCu's segment
  belongs to the SAPI that created it, so the running site keeps an index saying those
  rows are live and the return path is not armed until the item expires.

### Language codes at a public boundary

`trans_translations.locale` stores ICU locales and always will — that is what
`Laminas\I18n` resolves a catalog by. But a caller outside the application usually
means a *language*, and the region subtag is noise to it.

`JTranslate\I18n\LanguageMap` converts both ways, and
`Form\PhraseValidator::languages()` hands one out already built from the configured
set. schoenstatt.link's `/api/v3/phrases` uses it to speak `de` while storing `de_DE`.

**An ambiguous configuration throws rather than resolving.** Two locales sharing a
primary subtag — `pt_BR` beside `pt_PT` — make a language code stop identifying a
translation, and the failure that matters is the write: a caller's Portuguese would be
stored against whichever locale won, silently. A site that genuinely needs two variants
of one language has outgrown language codes at its boundary and has to say so
deliberately.

### Writing as somebody

`setActingUserId()` fixes the identity every subsequent write is stamped with,
overriding the configured provider. The provider reads the host application's session;
a caller authenticated by a bearer token has an identity and no session, and without
this every such write lands as `modified_by = NULL`. Named after
`SionModel\Db\Model\SionTable::setActingUserId()` so both surfaces read the same.

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
| Catalogs written into the source tree (`module/*/language/`, `language/`) | Still written among sources, though no longer *committed* here, and `jtranslate:export-catalogs` can rebuild them now. What remains for 3.0 is moving the write target out of deployed source entirely, into a cache directory — the real fix for the permissions complaints, since nothing the web server must write should live where a deploy also writes. |
| The admin GUI (`JTranslateController`, both forms, three `.phtml`) | ~480 lines built on `AbstractActionController`, laminas-form and `FlashMessenger`. It is the last part that requires laminas-mvc, and it is the part most worth rewriting rather than porting. |
| `Module::onBootstrap()`'s per-controller text-domain listener | Sets the text domain from the controller's root namespace on every dispatch, eagerly instantiating twelve view helpers to do it. Under a framework where the domain is an argument to `trans()`, this disappears. |
| `getTranslations()` loading the full user directory | It fetches every user to populate one attribution column, for every caller including those that never display it. |

## Upgrading

See [UPGRADE.md](UPGRADE.md) for the 1.x → 2.0 breaking changes. There are six,
all mechanical.

## Development

```
vendor/bin/phpcs                       # PSR-12 plus house sniffs; exits 0
php tools/verify-seed-provenance.php   # the seed holds only our own strings
```

The ruleset checks `src` and `config`. `language/` is excluded because those are
compiled catalogs whose line length is the length of a translated phrase — a
line-length rule there could never be satisfied and would fail on the next export.

This repository has no PHPUnit suite of its own; it is verified from the applications
that consume it, plus the provenance tool above. That is a gap, and closing it belongs
with 3.0.

## License

MIT.
