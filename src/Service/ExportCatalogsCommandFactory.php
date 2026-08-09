<?php

declare(strict_types=1);

namespace JTranslate\Service;

use JTranslate\Console\Command\ExportCatalogsCommand;
use JTranslate\Model\TranslationsTable;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class ExportCatalogsCommandFactory implements FactoryInterface
{
    /**
     * @param string $requestedName
     * @param array<string, mixed>|null $options
     */
    public function __invoke(
        ContainerInterface $container,
        $requestedName = ExportCatalogsCommand::class,
        ?array $options = null
    ): ExportCatalogsCommand {
        return new ExportCatalogsCommand($container->get(TranslationsTable::class));
    }
}
