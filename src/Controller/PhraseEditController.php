<?php

declare(strict_types=1);

namespace JTranslate\Controller;

use JTranslate\Form\EditPhraseForm;
use JTranslate\Host\Severity;
use JTranslate\Host\UrlBuilderInterface;
use JTranslate\Model\TranslationsTable;
use JTranslate\Page\PhraseAdmin;
use JTranslate\Routing\Routes;
use JTranslate\Twig\JTranslateExtension;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Environment;
use Twig\Error\Error as TwigError;

use function is_scalar;

/**
 * GET|POST /admin/translations/{phrase_id}/edit — one phrase, every locale, its history.
 *
 * Both methods on one route, because the laminas route has no method constraint either and
 * adding one would turn a mistaken GET into a 405 where today it renders the form.
 *
 * ## The three things a reader should not have to rediscover
 *
 * 1. **`getData()`, never the raw POST.** The submitted array has been through no filter at
 *    all, so writing it discards the trimming and the length checks `isValid()` just
 *    performed and stores exactly what the browser sent.
 * 2. **The posted `phraseId` is checked against the URL**, and a mismatch is not a
 *    validation error: it re-renders with a message. The hidden field is the form's own, so
 *    the only way to trip this is to edit it — and the check is what stops an edit of
 *    phrase A writing to phrase B.
 * 3. **The database write and the catalog export are two separate failures**, and reporting
 *    the second as the first is a lie a translator acts on. See
 *    {@see PhraseAdmin::exportCatalogs()}, which holds that reasoning; this controller only
 *    supplies the sentence, because the tense differs from the delete page's.
 *
 * ## Which messenger, and the defect that named the rule
 *
 * A failed validation used to be reported with a **flash** and the form then re-rendered —
 * so the translator saw a clean form with no explanation and found "Error in form
 * submission, please review." decorating whatever page they opened next, while the
 * field-level errors sat there unexplained. Every re-rendering branch here uses `now()` and
 * only the redirect flashes.
 *
 * The `phraseId` mismatch branch is the one that changes behaviour with that rule rather
 * than just wording: on laminas it flashed *and* returned a view, so its message landed on
 * the next page. It is the same message either way; it now arrives on the page that
 * caused it.
 */
final class PhraseEditController
{
    public function __construct(
        private readonly PhraseAdmin $admin,
        private readonly EditPhraseForm $form,
        private readonly Environment $twig,
        private readonly UrlBuilderInterface $urls
    ) {
    }

    /** @throws TwigError */
    public function __invoke(Request $request): Response
    {
        $phrase = $this->admin->phrase($request);
        if (null === $phrase) {
            $this->admin->flash(Severity::Error, 'Phrase not found.');

            return $this->toIndex();
        }
        $id = (int) $phrase['phraseId'];

        //`true`: the key locale is the language the phrases are written in, and the edit
        //form is the only screen where it is a field. Without it the source text would be
        //the one language nobody can correct.
        $locales = $this->admin->locales(true);
        //Read once for every branch below, because each of them re-renders the same form.
        //A successful save redirects, so there is no path where this is shown stale.
        $history = $this->admin->table()->getTranslationHistory($id);

        $form = $this->form;

        if ($request->isMethod('POST')) {
            /** @var array<string, mixed> $posted */
            $posted = $request->request->all();
            $form->setData($posted);

            /** @var mixed $postedId */
            $postedId = $posted['phraseId'] ?? null;
            //Compared as strings rather than loosely: the same answer for every value the
            //route constraint can produce, without treating a stray non-numeric body as a
            //match the way `0 == 'abc'` once would have.
            if (! is_scalar($postedId) || (string) $postedId !== (string) $id) {
                $this->admin->now(Severity::Error, 'Error in form submission, please review.');

                return $this->render($phrase, $id, $form, $locales, $history, $request);
            }

            if ($form->isValid()) {
                /** @var array<string, mixed> $data */
                $data = $form->getData();

                try {
                    $this->admin->table()->updatePhrase($id, $data);
                } catch (Throwable) {
                    $this->admin->now(Severity::Error, 'Error in form submission, please review.');

                    return $this->render($phrase, $id, $form, $locales, $history, $request);
                }

                //Past this point the row is written. The only question left is whether the
                //site can see it.
                if ($this->admin->exportCatalogs('edit')) {
                    $this->admin->flash(Severity::Success, 'Translations successfully updated.');
                } else {
                    $this->admin->flash(
                        Severity::Error,
                        'The translation was saved to the database, but the compiled translation '
                        . 'files could not be written, so the site will keep showing the old text '
                        . 'until that is fixed.'
                    );
                }

                return $this->toIndex();
            }

            $this->admin->now(Severity::Error, 'Error in form submission, please review.');
        } else {
            $form->setData($phrase);
        }

        return $this->render($phrase, $id, $form, $locales, $history, $request);
    }

    /**
     * @param array<string, mixed> $phrase
     * @param array<string, string> $locales
     * @param array<int, array<string, mixed>> $history
     * @throws TwigError
     */
    private function render(
        array $phrase,
        int $id,
        EditPhraseForm $form,
        array $locales,
        array $history,
        Request $request
    ): Response {
        return new Response($this->twig->render(JTranslateExtension::template('phrase-edit'), [
            'page_title' => 'Edit Translation',
            'phrase'     => $phrase,
            'phrase_id'  => $id,
            'form'       => $form,
            //The form posts to the URL it was requested at, which is what the laminas
            //action's `setAttribute('action', getRequestUri())` did. Taken from the request
            //rather than rebuilt from the route, so a query string survives a failed POST —
            //and passed as a variable rather than set on the form, because the host's
            //`form_open()` takes the action as an argument and ignores the attribute.
            'form_action' => $request->getRequestUri(),
            'locales'     => $locales,
            'history'     => $history,
            //The operation names, so the template matches on the model's constants rather
            //than on three string literals a rename could quietly orphan.
            'retired'      => TranslationsTable::OPERATION_RETIRE,
            'retire_undone' => TranslationsTable::OPERATION_UNRETIRE,
            'retracted'    => TranslationsTable::OPERATION_RETRACT,
        ]));
    }

    private function toIndex(): RedirectResponse
    {
        return new RedirectResponse($this->urls->path(Routes::INDEX), Response::HTTP_FOUND);
    }
}
