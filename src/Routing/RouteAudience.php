<?php

declare(strict_types=1);

namespace JTranslate\Routing;

/**
 * Who a route in this module's route fragment is meant to admit.
 *
 * One case, because this surface needs one answer: every route here is for an account
 * trusted to correct a translation, and nothing on it is public. It says that in this
 * module's vocabulary rather than in any host's — a host reading the fragment maps the
 * value onto whatever it has (an ACL resource, a Symfony Security rule, a firewall),
 * because the *names* are the host's: on schoenstatt.link `translator` and
 * `sch_general_moderator` are rows in a database table.
 *
 * ## Why an enum with a single case
 *
 * Because a route that loses its guard keeps working, and the fragment is the only place
 * the requirement is written down in terms that survive leaving laminas. An enum makes
 * the audience a value the host must *handle* — a `match` on it fails to compile a new
 * case away — where a comment saying "these are restricted" would be reconstructible
 * only from the host's own configuration.
 *
 * The failure mode is not an error, which is why it is worth this much ceremony: the
 * worklist publishes every phrase of the project, including free text a moderator wrote
 * as a per-locale description, and the delete confirmation becomes a URL anyone can POST
 * to. Both render perfectly well with no guard at all.
 *
 * A second case belongs here when, and only when, this module ships a route with a
 * genuinely different audience. Adding one speculatively would be a promise about access
 * that no consumer could act on.
 */
enum RouteAudience
{
    /** Trusted to read the worklist and to correct, retire or delete a phrase. */
    case Translator;
}
