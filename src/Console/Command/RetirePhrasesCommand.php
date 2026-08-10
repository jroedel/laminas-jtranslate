<?php

declare(strict_types=1);

namespace JTranslate\Console\Command;

use JTranslate\Model\TranslationsTable;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_column;
use function array_map;
use function count;
use function mb_strimwidth;
use function sprintf;

/**
 * Retires phrases, or brings them back.
 *
 * Retirement is what this library offers instead of deletion, and the reasoning is on
 * {@see TranslationsTable::retire()}. This is the surface that makes it usable in
 * bulk: without it, cleaning up after a removed feature means either hand-written SQL
 * or clicking delete several thousand times, and the second one destroys translations.
 *
 * ## The selection is deliberately blunt, and the safety is elsewhere
 *
 * `--origin-route` is a `LIKE` pattern, and `origin_route` records where a phrase was
 * **first seen**, not where it is used. The phrase index is keyed by text, so whichever
 * page rendered a string first owns its origin route forever: retiring
 * `--origin-route='blog%'` on schoenstatt.link would also catch `Original language`,
 * `Text successfully created.` and four other strings that are live elsewhere in the
 * site and merely had the bad luck to appear on a blog page first.
 *
 * That is fine, and it is the design. Every one of them un-retires itself the next time
 * a page renders it, keeping its translations. A selection that has to be *right* would
 * need an audit nobody can do; a selection that only has to be roughly right needs this
 * command and a week of traffic. What must never happen is somebody reaching for
 * `DELETE FROM trans_phrases WHERE origin_route LIKE 'blog%'` instead, which is the same
 * selection with none of the recovery.
 *
 * ## Always shows before it acts
 *
 * `--dry-run` prints the selection and changes nothing. Without it the command still
 * prints what it matched before writing, because a `LIKE` pattern with one character
 * wrong selects a plausible-looking set rather than an empty one.
 */
final class RetirePhrasesCommand extends Command
{
    /** How much of a phrase to show in the preview table. */
    private const PREVIEW_WIDTH = 60;

    public function __construct(private readonly TranslationsTable $table)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('jtranslate:retire')
            ->setDescription('Retire phrases from the translator worklist, reversibly, or un-retire them')
            ->addOption(
                'origin-route',
                null,
                InputOption::VALUE_REQUIRED,
                "Select by origin route, as a LIKE pattern — e.g. 'blog%'. Remember this records where a "
                . 'phrase was FIRST SEEN, not where it is used.'
            )
            ->addOption(
                'text-domain',
                null,
                InputOption::VALUE_REQUIRED,
                'Select by exact text domain'
            )
            ->addOption(
                'id',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Select by phrase id. Repeatable, and combinable with nothing else.'
            )
            ->addOption(
                'undo',
                null,
                InputOption::VALUE_NONE,
                'Un-retire the selection instead. Selecting by --origin-route or --text-domain then '
                . 'searches retired phrases rather than live ones.'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Print the selection and change nothing'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $undo = (bool) $input->getOption('undo');

        /** @var list<string> $ids */
        $ids = $input->getOption('id');
        if ([] !== $ids) {
            $selection = array_map('intval', $ids);
        } else {
            $selection = $this->selectByCriteria($io, $input, $undo);
            if (null === $selection) {
                return self::INVALID;
            }
        }

        if ([] === $selection) {
            $io->note('Nothing matched. No phrase was changed.');

            return self::SUCCESS;
        }

        if ($input->getOption('dry-run')) {
            $io->note(sprintf(
                '%d phrase(s) would be %s. Nothing was changed.',
                count($selection),
                $undo ? 'un-retired' : 'retired'
            ));

            return self::SUCCESS;
        }

        $affected = $undo ? $this->table->unretire($selection) : $this->table->retire($selection);

        $io->success(sprintf('%d phrase(s) %s.', $affected, $undo ? 'un-retired' : 'retired'));
        if (! $undo) {
            $io->note(
                'Their translations are untouched, and they still compile into the catalogs, so the site '
                . 'renders exactly what it rendered before.'
            );
            //Both caveats are on TranslationsTable::retire(), and both are easy to be
            //wrong about in the direction of expecting too much. Printing them here is
            //the difference between "it did not work" and "it has not been armed yet".
            $io->warning(
                "Two things this does NOT do:\n"
                . "  * It does not clear the web server's cache. APCu belongs to the SAPI that created "
                . "it, so the running site still holds a phrase index saying these rows are live, and "
                . "nothing will un-retire until that expires. Clear it now.\n"
                . '  * It does not bring back a phrase that is already translated in every locale. The '
                . 'return path fires on a missing translation, so a fully translated phrase stays retired '
                . 'until you run this again with --undo. It keeps rendering either way.'
            );
        }

        return self::SUCCESS;
    }

    /**
     * @return list<int>|null null when the caller gave no criteria at all
     */
    private function selectByCriteria(SymfonyStyle $io, InputInterface $input, bool $undo): ?array
    {
        $criteria = [];
        if (null !== $input->getOption('origin-route')) {
            $criteria['originRouteLike'] = (string) $input->getOption('origin-route');
        }
        if (null !== $input->getOption('text-domain')) {
            $criteria['textDomain'] = (string) $input->getOption('text-domain');
        }

        if ([] === $criteria) {
            //Refused rather than defaulted. The obvious default is "everything", and a
            //command that retires the entire project because it was run with no
            //arguments is not a command anybody should have to be careful with.
            $io->error('Give at least one of --id, --origin-route or --text-domain. There is no default.');

            return null;
        }

        //Un-retiring by pattern has to search the retired rows, which the default
        //listing hides; retiring by pattern has to search the live ones, or a re-run
        //would re-stamp retired_on and move the retirement date of rows that were
        //already done.
        $criteria['includeRetired'] = true;
        $criteria['onlyRetired']    = $undo;

        $matched = $this->table->getPhrasePage($criteria, PHP_INT_MAX, 0);

        $rows = [];
        foreach ($matched as $phrase) {
            $rows[] = [
                (string) $phrase['phraseId'],
                (string) $phrase['textDomain'],
                (string) ($phrase['originRoute'] ?? ''),
                mb_strimwidth((string) $phrase['phrase'], 0, self::PREVIEW_WIDTH, '…'),
            ];
        }
        $io->table(['id', 'text domain', 'origin route', 'phrase'], $rows);

        return array_map('intval', array_column($matched, 'phraseId'));
    }
}
