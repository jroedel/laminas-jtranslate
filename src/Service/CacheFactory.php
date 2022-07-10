<?php

declare(strict_types=1);

namespace JTranslate\Service;

use Laminas\Cache\StorageFactory;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class CacheFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null)
    {
        $options = $container->get('JTranslate\Config');

        return StorageFactory::factory($options['cache_options']);
    }
}
