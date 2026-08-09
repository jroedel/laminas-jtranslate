<?php

declare(strict_types=1);

namespace JTranslate\Service;

use JTranslate\Console\Command\MigrateCommand;
use JTranslate\Migration\MigrationRunner;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class MigrateCommandFactory implements FactoryInterface
{
    /**
     * @param string $requestedName
     * @param array<string, mixed>|null $options
     */
    public function __invoke(
        ContainerInterface $container,
        $requestedName = MigrateCommand::class,
        ?array $options = null
    ): MigrateCommand {
        return new MigrateCommand($container->get(MigrationRunner::class));
    }
}
