<?php

declare(strict_types=1);

namespace JTranslate\Twig;

use JTranslate\Host\UrlBuilderInterface;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;

use function dirname;

/**
 * What this module's templates are allowed to know about the application rendering them.
 *
 * Two things, and the reason there are only two is that everything else the three
 * templates need — `translate()`, `truncate()`, `short_date()`, `tooltip()`, the `form_*`
 * functions, the site chrome — is the host's, already exists in the host, and would be
 * worse for being reimplemented here.
 *
 * That list is worth reading as a *contract* rather than as trivia, because a host missing
 * any of it gets a Twig error naming a function and not a page: `translate`, `truncate`,
 * `short_date`, `tooltip`, `form_open`, `form_close`, `form_row`, `form_hidden`,
 * `form_submit`, `form_button`. The `form_*` set is
 * `SionModel\Twig\FormExtension` on the host this came from — a Bootstrap-3 renderer
 * reproducing what TwbBundle emitted for the `.phtml` these replace.
 *
 * ## `jtranslate_path()`
 *
 * Replaces the `url` view helper, which the three `.phtml` called between them six times.
 * The name changes rather than being kept, and that is the point: a template calling
 * `laminas_path()` only renders inside a host with a laminas router, which is exactly the
 * coupling this port removes. A host wires {@see UrlBuilderInterface} once and its router
 * stops being visible from any template here.
 *
 * ## `jtranslate_layout`
 *
 * A **global** rather than a function, because `{% extends %}` takes an expression and a
 * global is the plainest expression that can carry a per-host value. The chrome belongs to
 * the site: a package shipping its own would render the translation worklist looking like
 * nothing else on it.
 *
 * What the named layout must provide is a `content` block and somewhere to render
 * messages. A layout with no message rendering makes {@see \JTranslate\Host\FlashInterface}
 * silent — every message written, none shown — which on this surface hides the one thing a
 * translator must not miss: that a save committed but the catalogs did not.
 */
final class JTranslateExtension extends AbstractExtension implements GlobalsInterface
{
    /** The path a host whose layout is at the conventional place configures nothing for. */
    public const DEFAULT_LAYOUT = 'layout.html.twig';

    /**
     * The Twig namespace this module's own templates are addressed under — `@jtranslate/…`.
     *
     * A namespace rather than a bare directory on the loader's search path, because a host
     * has templates of its own and `phrase-edit.html.twig` is not a name anyone should have
     * to avoid. Every reference inside this module is fully qualified with it, so a host
     * registers the path once and nothing else can collide.
     */
    public const TEMPLATE_NAMESPACE = 'jtranslate';

    /**
     * @param string $layout the template `{% extends jtranslate_layout %}` resolves to;
     *        must be resolvable by the same Twig environment this extension is registered
     *        on
     */
    public function __construct(
        private readonly UrlBuilderInterface $urls,
        private readonly string $layout = self::DEFAULT_LAYOUT
    ) {
    }

    /** @return list<TwigFunction> */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('jtranslate_path', $this->path(...)),
        ];
    }

    /** @return array<string, mixed> */
    public function getGlobals(): array
    {
        return [
            'jtranslate_layout' => $this->layout,
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, string> $query
     */
    public function path(string $route, array $params = [], array $query = []): string
    {
        return $this->urls->path($route, $params, $query);
    }

    /**
     * Where this module's templates live on disk, for a host wiring the loader:
     *
     *     $loader->addPath(
     *         JTranslateExtension::templatePath(),
     *         JTranslateExtension::TEMPLATE_NAMESPACE
     *     );
     *
     * A method rather than documentation, because the answer depends on where composer put
     * the package and a host should never have to spell that out.
     */
    public static function templatePath(): string
    {
        return dirname(__DIR__, 2) . '/templates';
    }

    /**
     * One of this module's templates, fully qualified: `template('phrase-edit')` is
     * `@jtranslate/phrase-edit.html.twig`.
     *
     * Every reference goes through here so the namespace is named once. A bare
     * `'phrase-edit.html.twig'` would resolve against the *host's* loader paths and find
     * whatever it found — which is a template that renders, not an error.
     */
    public static function template(string $name): string
    {
        return '@' . self::TEMPLATE_NAMESPACE . '/' . $name . '.html.twig';
    }
}
