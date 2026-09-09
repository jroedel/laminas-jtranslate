<?php

declare(strict_types=1);

namespace JTranslate\Service;

use JTranslate\Cache\PhraseCache;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface;

use function is_int;
use function is_string;

/**
 * Builds {@see PhraseCache} around whatever PSR-16 cache the host provides.
 *
 * **The host provides the cache; this module builds none.** Until 2026-09 this factory
 * could also assemble a laminas-cache storage from `jtranslate.cache_options` and wrap it
 * in laminas's PSR-16 decorator. That made a translation library depend on a cache
 * implementation, which is backwards — and it is the last thing here that needed
 * laminas-cache, so it goes with it.
 *
 * A host names its cache service in `jtranslate.cache_service`; the service must return a
 * `Psr\SimpleCache\CacheInterface`. schoenstatt.link points it at a
 * `SionModel\Cache\ApcuStorage`, which implements that interface directly.
 *
 * Configuring nothing is allowed and means no *persistent* cache: `PhraseCache` keeps its
 * per-request memory either way, so the module works and merely re-reads the phrase index
 * once per request.
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
        $config       = $container->get('JTranslate\Config');
        $maxItemBytes = is_int($config['max_cache_item_bytes'] ?? null)
            ? $config['max_cache_item_bytes']
            : 2097152;

        return new PhraseCache($this->psrCache($container, $config), $maxItemBytes);
    }

    /** @param array<string, mixed> $config */
    private function psrCache(ContainerInterface $container, array $config): ?CacheInterface
    {
        $service = $config['cache_service'] ?? null;
        if (! is_string($service) || ! $container->has($service)) {
            return null;
        }
        $cache = $container->get($service);

        return $cache instanceof CacheInterface ? $cache : null;
    }
}
