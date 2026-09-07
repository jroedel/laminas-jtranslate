<?php

declare(strict_types=1);

namespace JTranslate\Controller;

use JTranslate\Form\DeletePhraseForm;
use JTranslate\Host\Severity;
use JTranslate\Host\UrlBuilderInterface;
use JTranslate\Page\PhraseAdmin;
use JTranslate\Routing\Routes;
use JTranslate\Twig\JTranslateExtension;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Twig\Error\Error as TwigError;

/**
 * GET|POST /admin/translations/{phrase_id}/delete — the confirmation, and the act.
 *
 * GET asks; POST destroys. No method constraint on the route, because adding one would
 * turn a mistaken GET into a 405 where today it renders the confirmation — and because the
 * confirmation *is* the protection a GET should get.
 *
 * ## What is being destroyed, since the word "delete" undersells it
 *
 * A phrase is not one record. `deletePhrase()` removes every translation of it in every
 * language, and the export that follows rewrites the catalogs, so the site stops serving
 * those strings — and it does not stay gone: the next page view that renders the same
 * source string files the phrase again, untranslated, which is exactly what the
 * confirmation text warns about.
 *
 * ## Three details carried over deliberately
 *
 * 1. **Cancel is checked before the CSRF token.** A cancellation is a no-op, and a stale
 *    token on one should not raise a form error about a thing the visitor asked not to do.
 *    `DeletePhraseForm` no longer renders Cancel as a submit button — this is the
 *    server-side half, for a hand-crafted POST or a page cached from before that fix. It
 *    matters more here than on the host's other delete pages: clicking Cancel used to
 *    delete the phrase.
 * 2. **A missing phrase is a message and a redirect**, not an error page. The laminas
 *    branch for it called `redirectAfterDelete()` — a method of no class in that
 *    hierarchy — so a stale delete link or a double submit was an uncaught `Error` rather
 *    than the sentence below.
 * 3. **The delete and the catalog export are separate failures**, in that order, and the
 *    second must not be reported as a failed delete. See
 *    {@see PhraseAdmin::exportCatalogs()}.
 *
 * ## The status code on a refused CSRF token is 400, and used to be 401
 *
 * `401 Unauthorized` is an authentication challenge: it tells a client to try again with
 * credentials, and a browser may prompt for them. A rejected CSRF token is none of that —
 * the visitor is signed in and permitted, the request body is malformed. It is corrected
 * here rather than reproduced, because the ported page is the one that will be around, and
 * a wrong 401 is the kind of thing another tool eventually acts on.
 */
final class PhraseDeleteController
{
    /**
     * The entity name the heading and the `<title>` are built from.
     *
     * A constant rather than a literal in two places, and hyphenated rather than spaced
     * because that is the string the phrase table already holds — `Delete
     * translation-phrase`, keyed exactly like that.
     */
    private const ENTITY = 'translation-phrase';

    public function __construct(
        private readonly PhraseAdmin $admin,
        private readonly Environment $twig,
        private readonly UrlBuilderInterface $urls
    ) {
    }

    /** @throws TwigError */
    public function __invoke(Request $request): Response
    {
        $id = $this->admin->phraseId($request);
        //existsPhrase() rather than the hydrated row: the confirmation and the POST that
        //acts must agree on one definition of "there is such a phrase", and this one is
        //also scoped to the project — another project's phrase is absent here, not
        //forbidden.
        if (null === $id || ! $this->admin->table()->existsPhrase($id)) {
            $this->admin->flash(Severity::Error, 'The entity you\'re trying to delete doesn\'t exists.');

            return $this->toIndex();
        }

        $form = $this->admin->deleteForm();

        if ($request->isMethod('POST')) {
            if (null !== $request->request->get('cancel')) {
                return $this->toIndex();
            }

            /** @var array<string, mixed> $posted */
            $posted = $request->request->all();
            $form->setData($posted);

            if ($form->isValid()) {
                $this->admin->table()->deletePhrase($id);

                if ($this->admin->exportCatalogs('deletion')) {
                    $this->admin->flash(Severity::Success, 'Entity successfully deleted.');
                } else {
                    $this->admin->flash(
                        Severity::Error,
                        'The phrase was deleted from the database, but the compiled '
                        . 'translation files could not be rewritten, so the site will go on showing '
                        . 'it until that is fixed.'
                    );
                }

                return $this->toIndex();
            }

            $this->admin->now(Severity::Error, 'Error in form submission, please review.');

            return $this->confirmation($id, $form, $request, Response::HTTP_BAD_REQUEST);
        }

        return $this->confirmation($id, $form, $request);
    }

    /** @throws TwigError */
    private function confirmation(
        int $id,
        DeletePhraseForm $form,
        Request $request,
        int $status = Response::HTTP_OK
    ): Response {
        //Re-read rather than carried down from __invoke(): existsPhrase() answered the
        //question this page is allowed to ask, and the row is what it has to *show*. The
        //warning names the phrase, which is the only thing telling a translator which one
        //they are about to destroy.
        $phrase = $this->admin->table()->getPhrase($id);

        return new Response(
            $this->twig->render(JTranslateExtension::template('phrase-delete'), [
                //'translation-phrase' is the entity name the laminas view interpolated into
                //its heading, and the heading is translated as a whole — see the template on
                //why that shape stays. `page_title` carries the same string, because the
                //layout is what renders the `<title>` the .phtml set with `headTitle()`.
                'page_title'  => 'Delete ' . self::ENTITY,
                'entity'      => self::ENTITY,
                'entity_id'   => $id,
                'phrase'      => $phrase,
                'form'        => $form,
                'form_action' => $request->getRequestUri(),
            ]),
            $status
        );
    }

    private function toIndex(): RedirectResponse
    {
        return new RedirectResponse($this->urls->path(Routes::INDEX), Response::HTTP_FOUND);
    }
}
