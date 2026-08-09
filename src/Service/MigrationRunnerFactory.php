<?php

declare(strict_types=1);

namespace JTranslate\Service;

use JTranslate\Migration\MigrationRunner;
use Laminas\Db\Adapter\Adapter;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class MigrationRunnerFactory implements FactoryInterface
{
    /**
     * @param string $requestedName
     * @param array<string, mixed>|null $options
     */
    public function __invoke(
        ContainerInterface $container,
        $requestedName = MigrationRunner::class,
        ?array $options = null
    ): MigrationRunner {
        /** @var array<string, mixed> $config */
        $config = $container->get('JTranslate\Config');

        //Deliberately the application's own adapter and no other. Supplying elevated
        //credentials here would put DDL rights in the container for every request to
        //reach; the answer to the missing rights is --pretend, not a second adapter.
        return new MigrationRunner($container->get(Adapter::class), $config);
    }
}
