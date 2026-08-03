<?php

namespace JTranslate\Service;

use Laminas\Cache\Service\StorageAdapterFactoryInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;
use SionModel\Cache\LegacyCacheConfig;

/**
 * Factory for building the cache storage
 */
class CacheFactory implements FactoryInterface
{
    /**
     * Create an object
     *
     * @inheritdoc
     */
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        $config = $container->get('JTranslate\Config');

        return $container->get(StorageAdapterFactoryInterface::class)
            ->createFromArrayConfiguration(LegacyCacheConfig::translate($config['cache_options']));
    }
}
