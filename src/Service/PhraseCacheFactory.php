<?php

declare(strict_types=1);

namespace JTranslate\Service;

use JTranslate\Cache\PhraseCache;
use Laminas\Cache\Psr\SimpleCache\SimpleCacheDecorator;
use Laminas\Cache\Service\StorageAdapterFactoryInterface;
use Laminas\Cache\Storage\StorageInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;

use function class_exists;
use function is_array;
use function is_int;

/**
 * Builds the {@see PhraseCache} the model uses, from whatever cache the host
 * application has configured.
 *
 * The model itself depends only on PhraseCache, which depends only on PSR-16. All the
 * laminas-cache knowledge — the storage adapter factory, the 2.x-to-3.x config shape
 * translation, the PSR-16 decorator — is confined to this file, so a Symfony host can
 * bind a PhraseCache built from any PSR-16 implementation and change nothing else.
 *
 * Every failure path here degrades to a cache-less PhraseCache rather than throwing.
 * A misconfigured cache should make the site slow, not down; that is a lesson from
 * this codebase's own history rather than a general principle.
 */
class PhraseCacheFactory implements FactoryInterface
{
    /**
     * @param string $requestedName
     * @param array<string, mixed>|null $options
     */
    public function __invoke(
        ContainerInterface $container,
        $requestedName = PhraseCache::class,
        ?array $options = null
    ): PhraseCache {
        /** @var array<string, mixed> $config */
        $config = $container->get('JTranslate\Config');

        $maxItemBytes = is_int($config['max_cache_item_bytes'] ?? null)
            ? $config['max_cache_item_bytes']
            : 2097152;

        return new PhraseCache($this->psrCache($container, $config), $maxItemBytes);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function psrCache(ContainerInterface $container, array $config): ?CacheInterface
    {
        //An application that already has a PSR-16 cache can name its service and skip
        //everything below. This is the seam a Symfony host uses.
        $service = $config['cache_service'] ?? null;
        if (is_string($service) && $container->has($service)) {
            $cache = $container->get($service);

            return $cache instanceof CacheInterface ? $cache : null;
        }

        $storage = $this->storage($container, $config);

        return null === $storage ? null : new SimpleCacheDecorator($storage);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function storage(ContainerInterface $container, array $config): ?StorageInterface
    {
        $options = $config['cache_options'] ?? null;
        if (! is_array($options) || ! $container->has(StorageAdapterFactoryInterface::class)) {
            return null;
        }

        //Deployment configs for this module predate laminas-cache 3 and are still
        //written in StorageFactory::factory() shape. SionModel carries the translation
        //between the two, but requiring SionModel to build a cache would undo the point
        //of this refactor, so it is used when present and skipped when not.
        $legacy = 'SionModel\Cache\LegacyCacheConfig';
        if (class_exists($legacy)) {
            /** @var array<string, mixed> $options */
            $options = $legacy::translate($options);
        }

        try {
            /** @var StorageAdapterFactoryInterface $factory */
            $factory = $container->get(StorageAdapterFactoryInterface::class);

            return $factory->createFromArrayConfiguration($options);
        } catch (Throwable) {
            //an unusable cache configuration is not a reason to refuse to translate
            return null;
        }
    }
}
