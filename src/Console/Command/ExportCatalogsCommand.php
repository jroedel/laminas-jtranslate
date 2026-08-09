<?php

declare(strict_types=1);

namespace JTranslate\Console\Command;

use JTranslate\Model\TranslationsTable;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function array_key_exists;
use function count;
use function getcwd;
use function glob;
use function is_array;
use function str_replace;

/**
 * Rebuilds every compiled catalog from the database.
 *
 * ## Why this had to exist before the catalogs could be deleted
 *
 * Until now the *only* way to write a `*.lang.php` file was for a human to save a
 * phrase in the admin GUI, or for the runtime discovery path to happen to find a
 * phrase that already had a translation in another text domain. There was no way to
 * rebuild. That is why the checked-in catalogs drifted so far from the database —
 * this library shipped four holding 9 phrases while the database held 26 — and it is
 * why deleting them was previously unsafe: nothing could put them back.
 *
 * With this command the catalogs are what they always should have been: derived
 * artifacts, reproducible from their source at any time.
 *
 * ## Where it writes, and the permission story
 *
 * The same places the GUI writes: `module/<TextDomain>/language/` when the text
 * domain names a loaded module, `<root>/language/<TextDomain>/` otherwise. Failures
 * raise with the offending path named, rather than being discarded the way
 * `writePhpTranslationArrays()` used to discard them.
 *
 * Run it as the user the web server runs as, not as root. Running it as root creates
 * catalogs the web server cannot subsequently replace, which is precisely the
 * ownership tangle that made this library look like it had a permissions bug.
 */
final class ExportCatalogsCommand extends Command
{
    public function __construct(private readonly TranslationsTable $table)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('jtranslate:export-catalogs')
            ->setDescription('Rebuild every compiled *.lang.php catalog from the database')
            ->addOption(
                'domain',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Restrict to these text domains. Useful when one target directory is not writable, '
                . 'since otherwise it stops every later domain from being rebuilt.'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'List the files that would be written, and write nothing'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        //Module discovery mirrors what the framework adapter does at bootstrap. It has
        //to be repeated here because this process never boots the MVC application, and
        //without it every text domain would be treated as a non-module and written to
        //<root>/language/<Domain>/ instead of into the module it belongs to.
        $this->table->setUserModules($this->moduleLanguageDirectories());

        /** @var list<string> $domains */
        $domains = $input->getOption('domain');
        $only    = [] === $domains ? null : $domains;

        if ($input->getOption('dry-run')) {
            return $this->dryRun($io, $only);
        }

        try {
            $written = $this->table->writePhpTranslationArrays($only);
        } catch (Throwable $e) {
            $io->error($e->getMessage());
            $io->note(
                'Nothing partial was left behind: each catalog is written to a temporary file and moved into '
                . 'place, so a failure leaves the previous version intact. Catalogs written before the failure '
                . 'are updated, which is safe — rerun once the path above is writable.'
            );

            return self::FAILURE;
        }

        $root = (string) getcwd();
        foreach ($written as $path) {
            $io->writeln('  ' . str_replace($root . '/', '', $path));
        }
        $io->success('Wrote ' . count($written) . ' catalog(s).');

        return self::SUCCESS;
    }

    /** @param list<string>|null $only */
    private function dryRun(SymfonyStyle $io, ?array $only): int
    {
        $tree  = $this->table->getTranslatedText();
        $count = 0;
        $rows  = [];
        foreach ($tree as $textDomain => $locales) {
            if (null !== $only && ! in_array((string) $textDomain, $only, true)) {
                continue;
            }
            foreach ($locales as $locale => $phrases) {
                $rows[] = [(string) $textDomain, (string) $locale, (string) count($phrases)];
                $count++;
            }
        }
        $io->table(['text domain', 'locale', 'phrases'], $rows);
        $io->note($count . ' catalog(s) would be written. Nothing was changed.');

        return self::SUCCESS;
    }

    /**
     * `module/<Name>/language` for every directory under `module/`.
     *
     * Unlike the framework adapter, this cannot filter by *loaded* module — there is
     * no module manager here. The consequence is narrow and acceptable: a text domain
     * matching a directory that exists but is not enabled would have its catalog
     * written into that directory rather than under `language/`. It would not be read
     * by anything, because the translator only registers patterns for loaded modules.
     *
     * @return array<string, string>
     */
    private function moduleLanguageDirectories(): array
    {
        $found = glob('module/*', GLOB_ONLYDIR);
        if (! is_array($found)) {
            return [];
        }

        $modules = [];
        foreach ($found as $dir) {
            $name = str_replace('module/', '', $dir);
            if (! array_key_exists($name, $modules)) {
                $modules[$name] = getcwd() . '/' . $dir . '/language';
            }
        }

        return $modules;
    }
}
