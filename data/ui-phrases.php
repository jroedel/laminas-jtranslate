<?php

/**
 * The phrases JTranslate's own admin GUI displays, with the translations this
 * library has shipped.
 *
 * Migration M002SeedUiPhrases inserts these for the configured `project_name` and
 * `key_locale`, so a fresh installation's translation GUI is not English-only in its
 * own chrome. Each phrase's key-locale value is the phrase itself, which is what the
 * runtime discovery path would have written anyway, so it is derived rather than
 * repeated here.
 *
 * ## Provenance, and the rule this file follows
 *
 * **Every phrase below is a string literal in this repository's own `src/` or
 * `templates/`, and every translation below was already committed to this repository.**
 * Nothing here came from any application's database. That rule is not bureaucratic:
 * `trans_phrases` is shared between the applications that use this library, so a
 * phrase in it belongs to whichever project contributed it and may be anything at
 * all — including data that must not leave that project. Seeding from a database
 * would exfiltrate one consumer's content into every installation of the library.
 * A library may ship only what its own source contains.
 *
 * The translations come from the four `language/*.lang.php` catalogs this library
 * used to carry, which is why only four phrases have any. Those catalogs were
 * deleted in 2.0 — they were build artifacts checked into source, regenerable by
 * `jtranslate:export-catalogs` — and this file is where the part of them that was
 * genuinely the library's own data now lives.
 *
 * Two phrases from those catalogs were deliberately left out: `Schoenstatt Link`,
 * which is a host application's name and not a string this library ever emits, and
 * `Italian (Italy)`, which is output of `TranslationsTable::getLocaleNames()` rather
 * than a literal in the UI. The locale names are on the 3.0 removal list in favour
 * of `\Locale::getDisplayName()`, which is already localised, so seeding them would
 * be seeding something scheduled for deletion.
 *
 * ## Maintaining it
 *
 * An empty array means the phrase has no shipped translation yet, not that it needs
 * none. Add locales freely; the migration is idempotent per phrase *and* per locale,
 * so re-running it fills in what is new and never overwrites what an installation
 * has. Add a phrase here when you add a translatable literal to this repository —
 * and only then.
 *
 * @return array<string, array<string, string>> phrase => locale => translation
 */

return [
    //templates/phrases-index.html.twig
    'Manage Translations'                                    => [],
    'Show pending translations'                               => [],
    'Show all translations'                                   => [],
    'Text Domain'                                             => [],
    'Key (English)'                                           => [],
    "Originated from the '%s' route"                           => [],
    'submitted on %s by %s'                                   => [],
    '%s previous version(s) — a translation here has been replaced before' => [],

    //templates/phrase-edit.html.twig
    'Edit Translation'                                        => [],
    //The history panel. Absent from this file until 2026-09-08 — the panel was added to
    //the .phtml in 2026-08 and the phrases were never seeded, so a fresh installation
    //discovered them one page view at a time instead.
    'Previous versions'                                       => [],
    'What writing to this phrase has replaced, newest first. Nothing here is rendered by'
    . ' the site any more — it is kept so a change can be reversed, and so the reason for'
    . ' a change survives the person who made it.'                                   => [],
    'Language'                                                => [],
    'Previous text'                                           => [],
    'Note'                                                    => [],
    'Replaced'                                                => [],
    'Put back on the worklist'                                => [],
    'Taken off the worklist'                                  => [],
    'withdrawn'                                               => [],

    //templates/phrase-delete.html.twig. The heading is built as
    //'Delete ' . $entity, and the controller's only entity is 'translation-phrase'.
    'Delete translation-phrase'                               => [],
    'Are you sure you want to delete the phrase "%s" from the translation database?'
    . ' Only delete if you\'re sure this translation is no longer used, otherwise it'
    . ' will reappear the next time the website encounters the same string.'         => [],

    //src/Controller/Phrase{Index,Edit,Delete}Controller.php and src/Page/PhraseAdmin.php
    'Phrase not found.'                                       => [],
    'Error in form submission, please review.'                 => [],
    'Translations successfully updated.'                       => [],
    'The entity you\'re trying to delete doesn\'t exists.'     => [],
    'The translation was saved to the database, but the compiled translation files'
    . ' could not be written, so the site will keep showing the old text until that'
    . ' is fixed.'                                                                   => [],
    'The phrase was deleted from the database, but the compiled translation files'
    . ' could not be rewritten, so the site will go on showing it until that is'
    . ' fixed.'                                                                      => [],
    'Entity successfully deleted.'                             => [
        'es_ES' => 'Entidad exitosamente eliminada.',
    ],

    //src/Form/EditPhraseForm.php
    'Phrase'                                                  => [],
    'Phrase not found in database'                             => [],
    'Submit'                                                   => [
        'de_DE' => 'Bestätigen',
        'es_ES' => 'Confirmar',
        'pt_BR' => 'Confirmar',
    ],

    //src/Form/DeletePhraseForm.php
    'Delete'                                                   => [
        'de_DE' => 'Löschen',
        'es_ES' => 'Eliminar',
        'pt_BR' => 'Excluir',
    ],
    'Cancel'                                                   => [
        'de_DE' => 'Abbrechen',
        'es_ES' => 'Cancelar',
        'pt_BR' => 'Cancelar',
    ],
];
