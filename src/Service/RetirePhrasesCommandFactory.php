<?php

declare(strict_types=1);

namespace JTranslate\Service;

use JTranslate\Console\Command\RetirePhrasesCommand;
use JTranslate\Model\TranslationsTable;
use Psr\Container\ContainerInterface;

class RetirePhrasesCommandFactory
{
    /**
     * @param string $requestedName
     * @param array<string, mixed>|null $options
     */
    public function __invoke(
        ContainerInterface $container,
        $requestedName = RetirePhrasesCommand::class,
        ?array $options = null
    ): RetirePhrasesCommand {
        return new RetirePhrasesCommand($container->get(TranslationsTable::class));
    }
}
