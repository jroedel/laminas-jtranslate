<?php

declare(strict_types=1);

namespace JTranslate\Page;

use JTranslate\Form\DeletePhraseForm;
use JTranslate\Host\FlashInterface;
use JTranslate\Host\Severity;
use JTranslate\I18n\TranslatableMessage;
use JTranslate\Model\TranslationsTable;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

use function ctype_digit;
use function get_class;
use function is_array;
use function is_int;
use function is_string;

/**
 * What the three controllers of the translation-administration surface share.
 *
 * Deliberately plumbing rather than policy — the table, the phrase behind a route
 * parameter, the locale list, the two messengers — with one exception that is the most
 * valuable thing in the file: {@see exportCatalogs()}, which is where the "the database
 * write already committed" reasoning lives.
 *
 * ## There is no refuse() here
 *
 * All three routes are declared {@see \JTranslate\Routing\RouteAudience::Translator}, and
 * on the host this was ported from that maps to `translator` and `sch_general_moderator`
 * — neither of which is a default role — so the route audience really is the whole
 * protection and there is nothing left for a controller to check. That is the *unusual*
 * case on that host, where four of the roles its guards name are `is_default = 1` and the
 * real check has to live inside the page. A reader arriving from one of those surfaces
 * will look for the missing check; this is the answer.
 *
 * ## What is not shared: the edit form
 *
 * `EditPhraseForm` goes to the one controller that renders it, not through here. It is a
 * *shared* service on a laminas host and the page mutates it (`setData()`, and the
 * `action` attribute), so passing it around would spread that assumption over three
 * classes to save nothing; the delete form is the opposite and is built fresh per call —
 * see {@see deleteForm()}.
 */
final class PhraseAdmin
{
    public function __construct(
        private readonly TranslationsTable $phrases,
        private readonly FlashInterface $messages,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    public function table(): TranslationsTable
    {
        return $this->phrases;
    }

    /**
     * The phrase behind a `{phrase_id}` route parameter, or null when there is none.
     *
     * Null covers three cases the callers treat identically and which are worth naming,
     * because only the first is a bug in somebody's link: a parameter that is not a
     * number, a phrase this project does not own, and one that no longer exists. The
     * middle one is the interesting case — `trans_phrases` is shared between projects
     * (875 of the 4,237 rows on the host this came from belong to another application),
     * and `getPhraseById()` filters on `project`, so another project's phrase is *absent*
     * here rather than forbidden. That is the right answer and not a leak: reporting it
     * as "denied" would confirm the row exists.
     *
     * `'0'` is rejected rather than looked up, since a route constraint of `[0-9]+`
     * matches `0` and `00000`.
     *
     * @return array<string, mixed>|null
     */
    public function phrase(Request $request): ?array
    {
        $id = $this->phraseId($request);
        if (null === $id) {
            return null;
        }

        /** @var mixed $phrase */
        $phrase = $this->phrases->getPhrase($id);

        return is_array($phrase) ? $phrase : null;
    }

    /**
     * The `{phrase_id}` route parameter as an int, or null when it is not a usable id.
     *
     * Separate from {@see phrase()} because the delete action needs the id itself: it
     * asks `existsPhrase()` rather than hydrating the row, so that the confirmation page
     * and the POST that acts agree on one definition of "there is such a phrase".
     */
    public function phraseId(Request $request): ?int
    {
        /** @var mixed $raw */
        $raw = $request->attributes->get('phrase_id');
        $raw = is_string($raw) || is_int($raw) ? (string) $raw : '';
        if (! ctype_digit($raw) || 0 === (int) $raw) {
            return null;
        }

        return (int) $raw;
    }

    /**
     * The locales this installation translates into.
     *
     * @param bool $includeKeyLocale the language the phrases themselves are written in.
     *        **The edit form needs it and the listing must not have it**: a phrase's key
     *        text is the English column of the table, so including it there would render
     *        it twice, and excluding it from the form would make the key locale the one
     *        language nobody can correct.
     * @return array<string, string> locale => its own name
     */
    public function locales(bool $includeKeyLocale = false): array
    {
        /** @var array<string, string> $locales */
        $locales = $this->phrases->getLocales($includeKeyLocale);

        return $locales;
    }

    /**
     * A delete form, built fresh.
     *
     * Never shared and never cached: a form carries the data and the validation messages
     * of whatever was last put through it, and its CSRF element is the whole protection on
     * a page that destroys a phrase.
     */
    public function deleteForm(): DeletePhraseForm
    {
        return new DeletePhraseForm();
    }

    /**
     * Rewrite the compiled catalogs, and answer with the message to show on failure.
     *
     * **This is the half that a caller must not skip and must not report as its own
     * failure**, and both halves of that were bugs in the laminas GUI:
     *
     * - The site does not read translations from the database — it reads the compiled
     *   `.lang.php` catalogs — so a phrase edited or deleted without this call goes on
     *   being served as it was, indefinitely, until some unrelated write happens to
     *   rewrite the files. The GUI reported success while the site showed the old text.
     * - By the time this runs the database write has already committed, so a failure here
     *   is *not* "your submission was wrong". Reporting it as "Error in form submission,
     *   please review" sends a translator back to re-edit a phrase that is already
     *   correct; reporting it as success tells them the opposite of the truth. Both were
     *   shipped, in that order.
     *
     * The exception text is **logged, not shown**, and that is not squeamishness about
     * internals: messages are run through the translator when they render, so a message
     * carrying `$e->getMessage()` records itself as a missing translation and writes a new
     * permanent phrase row for every distinct filesystem error — each one unique, none of
     * them translatable, on the very screen whose job is to keep that table clean.
     *
     * ## Why the message is the caller's and not this method's
     *
     * The two callers say different things — "the translation was saved … will keep
     * showing the old text" and "the phrase was deleted … will go on showing it" — and
     * both sentences already exist as phrase rows in this module's own text domain
     * (12814 and 12815 on the host this came from). Folding them into one would destroy
     * whatever translations they carry and file a third phrase, on the screen whose
     * purpose is to keep that table clean. So this method reports success and logs the
     * cause; the sentence stays where the tense is decided.
     *
     * @param string $what for the log line: `'edit'` or `'deletion'`
     * @return bool whether the catalogs were written
     */
    public function exportCatalogs(string $what): bool
    {
        try {
            $this->phrases->writePhpTranslationArrays();

            return true;
        } catch (Throwable $e) {
            $this->logger?->error(
                'JTranslate: could not compile translation files after a ' . $what . '.',
                [
                    'exceptionClass' => get_class($e),
                    'exception'      => $e->getMessage(),
                    'trace'          => $e->getTraceAsString(),
                ]
            );

            return false;
        }
    }

    /**
     * Survives a redirect; read by the next page rendered in this session.
     *
     * @param string|TranslatableMessage $message
     */
    public function flash(Severity $severity, string|TranslatableMessage $message): void
    {
        $this->messages->flash($severity, $message);
    }

    /**
     * Rendered by the response being returned now.
     *
     * @param string|TranslatableMessage $message
     */
    public function now(Severity $severity, string|TranslatableMessage $message): void
    {
        $this->messages->now($severity, $message);
    }
}
