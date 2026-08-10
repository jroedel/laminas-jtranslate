<?php

declare(strict_types=1);

namespace JTranslate\Cache;

use Psr\SimpleCache\CacheInterface;
use Throwable;

use function is_array;
use function serialize;
use function strlen;

/**
 * The two derived arrays JTranslate re-reads on every request, held in memory for
 * the request and in a PSR-16 cache between them.
 *
 * ## Why not SionCacheTrait
 *
 * This replaces `SionModel\Db\Model\SionCacheTrait`, which JTranslate used to pull
 * in wholesale. The trait is built for `SionTable`, which caches many derived views
 * of many entity types and therefore needs a dependency graph — a persisted map of
 * cache key to the entity names that invalidate it — plus a deferred write queue and
 * a generation counter. JTranslate has **one** entity ('phrase') and **two** derived
 * items, and every write invalidated both of them, so all of that machinery resolved
 * to "clear both keys". Depending on another application's model base class for that
 * is what stopped this module from being installable on its own.
 *
 * Three behaviours from the trait are deliberately kept, because each of them was
 * learned from a production incident rather than designed in:
 *
 * - **A size budget.** An `apcu_store()` that cannot fit does not fail politely: with
 *   `apc.ttl=0` a failed allocation clears the *entire* segment, so one oversized item
 *   evicts everything every other part of the application cached. That took the live
 *   site down on 2026-08-03. Refusing to write an item that exceeds the budget is
 *   strictly better than attempting it. The default is 2 MiB against production's
 *   32 MB segment.
 * - **A cache failure is never a page failure.** Laminas' APCu adapter throws when the
 *   segment is full, and these calls sit deep inside query paths, so an uncaught
 *   throw turns a full cache into a 500 on any page that happens to read a phrase.
 *   Every persistent call here is wrapped.
 * - **The memory layer.** Repeated reads inside one request must not re-hit APCu.
 *
 * One behaviour is deliberately dropped: the trait deferred its writes to
 * `MvcEvent::EVENT_FINISH`. That deferral is exactly what let a write at priority 100
 * be undone by an eviction at priority -1 inside the same request, and there is
 * nothing to batch here — two keys. Writing through is simpler and cannot race with
 * itself.
 */
final class PhraseCache
{
    /**
     * Every phrase this project has, as domain => hex phrase hash => is retired.
     *
     * @see \JTranslate\Model\TranslationsTable::getPhraseIndex() for why it is hashed
     *      rather than stored whole, and why the value is a flag rather than `true`.
     *
     * The `2` is a shape version, and it is doing real work here rather than being a
     * cautious habit. The v1 item was `domain => md5 => true`; the v2 item is
     * `domain => sha256hex => bool`. A deploy that reused the key would find v1 items
     * still in a shared APCu segment and read every one of their `true` values as
     * "this phrase is retired", so the render path would queue an un-retire for every
     * phrase on every page until the TTL expired. Bumping the key makes the old item
     * unreachable instead of misread.
     */
    public const KEY_PHRASE_INDEX = 'jtranslate.phrase_index.2';

    /** The compiled text domain / locale / phrase => translation tree. */
    public const KEY_TRANSLATED_TEXT = 'jtranslate.translated_text';

    /**
     * PSR-16 only guarantees `A-Za-z0-9_.` in keys, which is why these use a dot and
     * an underscore rather than the hyphenated names the trait generated from the
     * class name. They are also unversioned on purpose: a deployment that changes the
     * shape of either value should bump the constant, not rely on a TTL to expire a
     * stale shape.
     */
    private const KEYS = [self::KEY_PHRASE_INDEX, self::KEY_TRANSLATED_TEXT];

    private const DEFAULT_MAX_ITEM_BYTES = 2097152;

    /** @var array<string, mixed> */
    private array $memory = [];

    /**
     * @param CacheInterface|null $persistent null disables persistence entirely; the
     *        memory layer still works, so the module runs without a cache configured.
     * @param int $maxItemBytes zero or less disables the size check
     */
    public function __construct(
        private readonly ?CacheInterface $persistent = null,
        private readonly int $maxItemBytes = self::DEFAULT_MAX_ITEM_BYTES,
    ) {
    }

    /**
     * @return array<array-key, mixed>|null null when the item is absent, which the
     *         caller must treat as "recompute", never as "empty".
     */
    public function get(string $key): ?array
    {
        if (isset($this->memory[$key])) {
            return $this->memory[$key];
        }
        if (null === $this->persistent) {
            return null;
        }

        try {
            $value = $this->persistent->get($key);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($value)) {
            return null;
        }
        $this->memory[$key] = $value;

        return $value;
    }

    /**
     * @param array<array-key, mixed> $value
     * @return bool whether it reached the persistent cache. False is normal — the item
     *         may simply be over budget — and is not worth raising, but it is worth
     *         being able to see.
     */
    public function set(string $key, array $value): bool
    {
        $this->memory[$key] = $value;

        if (null === $this->persistent || $this->exceedsBudget($value)) {
            return false;
        }

        try {
            return $this->persistent->set($key, $value);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Drop everything derived from the phrase tables, in both layers.
     *
     * Called after any write to `trans_phrases` or `trans_translations`. There is no
     * finer granularity on purpose: both items derive from both tables, so any change
     * invalidates both, and a dependency map that always answers "all of them" is a
     * map worth deleting.
     */
    public function clear(): void
    {
        $this->memory = [];

        if (null === $this->persistent) {
            return;
        }

        try {
            $this->persistent->deleteMultiple(self::KEYS);
        } catch (Throwable) {
            //a cache we cannot clear is a correctness problem for the next reader, but
            //raising here would fail the write that just succeeded. The TTL bounds it.
        }
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function exceedsBudget(array $value): bool
    {
        if ($this->maxItemBytes <= 0) {
            return false;
        }

        return strlen(serialize($value)) > $this->maxItemBytes;
    }
}
