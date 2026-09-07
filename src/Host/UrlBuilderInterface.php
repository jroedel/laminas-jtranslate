<?php

declare(strict_types=1);

namespace JTranslate\Host;

/**
 * Builds a URL for a route the **host** owns, from inside this module.
 *
 * Every page this module's GUI serves links to pages it does not declare, and it links to
 * its own by name too — the worklist's pencil and delete icons, the form's `action`
 * attribute, and the redirect every write ends with. A route name means nothing without a
 * router, and which router that is, is exactly what this module has stopped deciding for
 * this surface.
 *
 * ## What it replaces
 *
 * `Laminas\Router\RouteStackInterface::assemble()`, reached two ways in the GUI: the
 * `url` view helper in the three `.phtml`, and `$this->redirect()->toRoute()` /
 * `$this->url()->fromRoute()` in the controller.
 *
 * ## Why the locale prefix is not this interface's business
 *
 * On schoenstatt.link an assembled path carries a locale segment (`/en/admin/translations`),
 * put there by SlmLocale setting the router's base URL. That is a property of the host's
 * routing, not of a URL builder, and a host with no locales implements the same two
 * methods and returns paths without one. This module never inspects, strips or appends a
 * prefix.
 *
 * ## `url()` is here for symmetry and has no caller yet
 *
 * Unlike JUser's identical method, nothing on this surface needs an absolute URL: there
 * is no email in the translation GUI. It is declared because a host implementing this over
 * a router already has both, and because the one thing that *would* need it — a
 * notification telling a translator there is work waiting — is a plausible next feature
 * whose absence should not be a contract change. An implementation may not throw from it.
 */
interface UrlBuilderInterface
{
    /**
     * A root-relative path, e.g. `/en/admin/translations/6192/edit`.
     *
     * @param string $route a route name the host recognises
     * @param array<string, mixed> $params route parameters, e.g. `['phrase_id' => 6192]`
     * @param array<string, string> $query appended as a query string; already-decoded
     *        values, encoded by the implementation
     */
    public function path(string $route, array $params = [], array $query = []): string;

    /**
     * The same, absolute: scheme, host and path.
     *
     * @param array<string, mixed> $params
     * @param array<string, string> $query
     */
    public function url(string $route, array $params = [], array $query = []): string;
}
