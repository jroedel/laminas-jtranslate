<?php

declare(strict_types=1);

namespace JTranslate\Routing;

/**
 * The names of the routes this module's GUI is served at.
 *
 * Constants rather than literals because three separate things have to agree on each
 * string — the route fragment that declares it, the code that builds a link to it, and
 * the host's guard entry — and a mismatch between the first two is a dead link while a
 * mismatch with the third silently changes who may reach the page.
 *
 * ## The names are the laminas ones, and the paths are `/admin/translations`
 *
 * Both were already true before the GUI moved to Symfony, and neither changes with it:
 * the consuming application's `/admin` page links here by route *name*
 * (`App\Schoenstatt\AdminIndex`), its ACL guard entries are keyed on `route/jtranslate`
 * and `route/jtranslate/phrase/{edit,delete}`, and `docs/acl-baseline.json` is a
 * committed snapshot of exactly those keys. Renaming any of them would be a coordinated
 * change across two repositories in exchange for tidier strings.
 *
 * ## There is no constant for the parent
 *
 * `jtranslate/phrase` exists in the host's laminas router as the parent of the two
 * below, with `may_terminate => false` and no action of its own — so it is not a page,
 * has no guard entry, and nothing can link to it. A constant for it would be an
 * invitation to assemble a URL that throws (`Laminas\Router\Http\Part::assemble()`
 * refuses a non-terminating route).
 */
final class Routes
{
    /** `/admin/translations` — the worklist. */
    public const INDEX = 'jtranslate';

    /** `/admin/translations/{phrase_id}/edit` — one phrase, every locale, and its history. */
    public const PHRASE_EDIT = 'jtranslate/phrase/edit';

    /**
     * `/admin/translations/{phrase_id}/delete` — the confirmation, and the POST that acts.
     *
     * Worth naming what it destroys, because the name reads smaller than the act: a phrase
     * is not one record. Deleting it removes every translation of it in every language and
     * then rewrites the compiled catalogs, so the site stops serving those strings.
     */
    public const PHRASE_DELETE = 'jtranslate/phrase/delete';
}
