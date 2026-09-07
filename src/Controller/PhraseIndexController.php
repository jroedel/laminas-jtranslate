<?php

declare(strict_types=1);

namespace JTranslate\Controller;

use JTranslate\Page\PhraseAdmin;
use JTranslate\Twig\JTranslateExtension;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Twig\Error\Error as TwigError;

use function is_array;
use function is_string;

/**
 * GET /admin/translations — the worklist.
 *
 * One row per phrase this project owns, one column per locale it is translated into, and
 * two query parameters that between them decide what is on it.
 *
 * ## `showAll`, and where the filter now lives
 *
 * Without it the page lists only phrases some locale is still missing; with it, all of
 * them. That filter used to be computed *inside the view*, as a `continue` in the row
 * loop, which is why it moves here: a template that decides which rows exist cannot be
 * rendered against a row set for any other purpose, and the same test is what
 * {@see selectedLocales()} has to agree with.
 *
 * **The two states are much closer together than they sound**, and it is worth knowing
 * before reading a diff of this page: measured on the capsule, the filtered listing showed
 * 3,287 phrases and the unfiltered one 3,304. Nearly every phrase is missing at least one
 * of the four languages, so `showAll` is not the difference between a short page and a long
 * one — both are ~2.2 MB — and a change that quietly stopped filtering would look right.
 *
 * ## `locale`
 *
 * Either one locale (`?locale=de_DE`) or several (`?locale[]=de_DE&locale[]=es_ES`), and
 * anything unrecognised is dropped rather than refused. An empty result means *all*
 * locales, which is what makes a bare `/admin/translations` the full table.
 *
 * The subtlety, reproduced deliberately: the selection narrows the columns **and** the
 * definition of "pending". Asking for `?locale=de_DE` lists the phrases missing German,
 * not the phrases missing something. Any other reading would make the two parameters
 * interact in a way nobody could predict from the page.
 */
final class PhraseIndexController
{
    public function __construct(
        private readonly PhraseAdmin $admin,
        private readonly Environment $twig
    ) {
    }

    /** @throws TwigError */
    public function __invoke(Request $request): Response
    {
        //`== "true"`, as the laminas action wrote it: any other value, including `1`, is
        //off. Kept rather than loosened because the two links on the page are the only
        //things that set it and both send exactly this.
        $showAll = 'true' === $request->query->get('showAll');
        $locales = $this->selectedLocales($request);

        $translations = $this->admin->table()->getTranslations();

        return new Response($this->twig->render(JTranslateExtension::template('phrases-index'), [
            'page_title'    => 'Manage Translations',
            'translations'  => $showAll ? $translations : $this->pendingOnly($translations, $locales),
            'locales'       => $locales,
            'show_all'      => $showAll,
            //One query for the whole listing, not one per row: this table renders every
            //phrase of the project, so a per-row query would be three thousand of them for
            //an icon. Phrases with no history are absent from the map rather than zero,
            //which is why the template's test is a `default(0)`.
            'history_counts' => $this->admin->table()->getHistoryCounts(),
        ]));
    }

    /**
     * The locales whose columns this request wants, defaulting to every one of them.
     *
     * Keyed by locale and carrying its name, in the table's own order rather than in the
     * order they were asked for — the columns are the same columns whatever the query
     * string says.
     *
     * @return array<string, string>
     */
    private function selectedLocales(Request $request): array
    {
        $available = $this->admin->locales();

        /** @var mixed $requested */
        $requested = $request->query->all()['locale'] ?? null;
        if (is_string($requested)) {
            $requested = [$requested];
        }
        if (! is_array($requested)) {
            return $available;
        }

        $selected = [];
        foreach ($available as $locale => $name) {
            /** @var mixed $candidate */
            foreach ($requested as $candidate) {
                if (is_string($candidate) && $candidate === $locale) {
                    $selected[$locale] = $name;
                }
            }
        }

        return [] === $selected ? $available : $selected;
    }

    /**
     * Only the phrases that are missing at least one of `$locales`.
     *
     * `isset()` on the locale key is the test, which means an **empty string counts as
     * translated** — the row is present, so the phrase is not on the worklist. That is the
     * laminas behaviour, reproduced rather than corrected, because whether a blank
     * translation is work outstanding is a data question and not a rendering one.
     *
     * It is not hypothetical, and it is smaller than it looks: exactly one row of
     * `trans_translations` is blank on the host this came from (id 12876, `en_US`, written
     * 2019-08-16), and the phrase it belongs to — 7469 — has a blank `phrase` too. So the
     * one row this rule hides is a junk row that renders as nothing either way. Filed in
     * the host's backlog rather than deleted from under a port.
     *
     * @param array<int, array<string, mixed>> $translations
     * @param array<string, string> $locales
     * @return array<int, array<string, mixed>>
     */
    private function pendingOnly(array $translations, array $locales): array
    {
        $pending = [];
        foreach ($translations as $phraseId => $phrase) {
            foreach ($locales as $locale => $name) {
                if (! isset($phrase[$locale])) {
                    $pending[$phraseId] = $phrase;
                    break;
                }
            }
        }

        return $pending;
    }
}
