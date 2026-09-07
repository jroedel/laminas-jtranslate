<?php

/**
 * This module's HTTP routes, described for a host to declare.
 *
 * A **fragment, not a route collection**: it returns a closure that calls back into
 * whatever the host uses to register a route — the same design as
 * `module/JUser/config/symfony-routes.php`, and for the same reason. Returning a
 * `Symfony\Component\Routing\RouteCollection` would be shorter and would force every host
 * into one routing library, one way of attaching authorization, and one set of defaults;
 * on schoenstatt.link a ported route needs a per-route ACL declaration and a translation
 * text domain in its defaults, neither of which this module knows anything about.
 *
 * ## The callback
 *
 *     $declare(
 *         string $name,                    // JTranslate\Routing\Routes names them
 *         string $path,                    // no locale prefix, no base URL
 *         string|array $controller,        // FQCN, or [FQCN, 'method']
 *         RouteAudience $audience,         // who may reach it
 *         array $defaults = [],            // route defaults this module needs
 *         array $requirements = []         // parameter constraints
 *     ): void
 *
 * The host adds its own defaults on top — a text domain, whatever its kernel needs — and
 * translates `$audience` into its own authorization vocabulary.
 *
 * ## Order
 *
 * As written, and it matters for any matcher that takes the first match: the listing's
 * path is a prefix of the other two. All three are distinguishable by shape alone, so no
 * real matcher would confuse them — being deliberate about the order costs nothing.
 *
 * ## `phrase_id` is `[0-9]+`, and the laminas route says `[0-9]{1,5}`
 *
 * The difference is deliberate and is the one place this fragment does not reproduce the
 * route it replaces. `trans_phrases.translation_phrase_id` is an `int(11)` auto-increment
 * already at **14,434** on the host this came from, and it grows every time a page renders
 * a string nobody has recorded yet — 61% of that table arrived in a single day once. At
 * 100,000 the five-digit constraint stops matching, and the failure is silent in the worst
 * way: the phrase is on the worklist, its pencil link is rendered, and following it answers
 * 404 while every other phrase works.
 *
 * Nothing else on the path can be swallowed by widening it — the segments after
 * `{phrase_id}` are literal — so there is no ordering consequence. The laminas constraint
 * is left alone rather than widened in step: that route now exists only so the host's
 * `laminas_path()` and its ACL guards can name it, and nothing dispatches it.
 */

declare(strict_types=1);

use JTranslate\Controller\PhraseDeleteController;
use JTranslate\Controller\PhraseEditController;
use JTranslate\Controller\PhraseIndexController;
use JTranslate\Routing\RouteAudience;
use JTranslate\Routing\Routes;

return static function (callable $declare): void {
    //The worklist. Every phrase this project owns, filtered to the pending ones unless
    //`?showAll=true`.
    $declare(Routes::INDEX, '/admin/translations', PhraseIndexController::class, RouteAudience::Translator);

    //One phrase in every language, plus what writing to it has replaced before. GET renders,
    //POST writes; no method constraint, because the laminas route has none and adding one
    //would turn a mistaken GET into a 405 where today it renders the form.
    $declare(
        Routes::PHRASE_EDIT,
        '/admin/translations/{phrase_id}/edit',
        PhraseEditController::class,
        RouteAudience::Translator,
        [],
        ['phrase_id' => '[0-9]+']
    );

    //GET asks, POST destroys — and what it destroys is every translation of the phrase in
    //every language, followed by a rewrite of the compiled catalogs. No method constraint,
    //for the reason above and because the confirmation *is* what a GET should get.
    $declare(
        Routes::PHRASE_DELETE,
        '/admin/translations/{phrase_id}/delete',
        PhraseDeleteController::class,
        RouteAudience::Translator,
        [],
        ['phrase_id' => '[0-9]+']
    );
};
