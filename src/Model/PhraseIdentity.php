<?php

declare(strict_types=1);

namespace JTranslate\Model;

use function bin2hex;
use function hash;

/**
 * What makes two phrases the same phrase.
 *
 * The answer is byte identity, because that is the relation
 * `Laminas\I18n\Translator` applies to its catalog keys: a lookup for `Save` will
 * never find a catalog entry for `save`, so the two are different phrases and must be
 * different rows. Nothing in MySQL expresses that relation — `utf8mb4_unicode_520_ci`
 * is case-insensitive, accent-insensitive and PAD SPACE, and even `utf8mb4_bin` cannot
 * be indexed over a `TEXT` column without truncation. Hashing is how the relation gets
 * into the schema.
 *
 * This class exists so there is exactly one implementation of it. There used to be
 * two spellings of `md5($phrase)` in `TranslationsTable` and a third rule — "match on
 * the phrase column" — in M002, and the three did not agree.
 *
 * ## Why SHA-256
 *
 * The hash was `md5()` while it only ever backed an in-memory membership set, where a
 * collision costs nothing anybody can observe. It now backs
 * `UNIQUE (project, text_domain, phrase_hash)`, and there a collision means a phrase
 * that can never be inserted and therefore never translated. Phrase text is partly
 * editorial content — association names, publication titles — so it is partly
 * attacker-chosen, and md5 chosen-prefix collisions are cheap to produce. SHA-256
 * costs the same to compute at this volume and sixteen more bytes to store.
 *
 * ## No normalization, ever
 *
 * Not trimmed, not case-folded, not Unicode-normalized. The hash must identify the
 * exact string the translator will use as its key; anything that maps two distinct
 * runtime strings onto one hash reintroduces the defect M003 describes, where the
 * stored row could never be recognised again and every render inserted another copy.
 *
 * ## Two encodings, deliberately
 *
 * {@see raw()} is what the `BINARY(32)` column stores, at a third the index width of
 * hex under `utf8mb4`. {@see hex()} is what the in-memory and APCu phrase index keys
 * on, because that cache is serialized — the configured PSR-16 adapter runs a JSON
 * serializer plugin, and JSON cannot carry arbitrary bytes in an object key.
 */
final class PhraseIdentity
{
    /** The algorithm name, exposed so a migration or a test can name the same one. */
    public const ALGORITHM = 'sha256';

    /** Bytes in a {@see raw()} digest, i.e. the width of the `phrase_hash` column. */
    public const LENGTH = 32;

    /**
     * The 32 raw bytes stored in `trans_phrases.phrase_hash`.
     */
    public static function raw(string $phrase): string
    {
        return hash(self::ALGORITHM, $phrase, true);
    }

    /**
     * The same digest as 64 lower-case hex characters, for use as an array key.
     */
    public static function hex(string $phrase): string
    {
        return hash(self::ALGORITHM, $phrase);
    }

    /**
     * A stored digest re-encoded as an array key, without rehashing the phrase.
     *
     * The phrase index is built from a query that reads `phrase_hash` rather than
     * `phrase` — 32 bytes a row instead of the whole corpus — so the rows arrive
     * already hashed and only need the encoding changed.
     */
    public static function hexOf(string $rawHash): string
    {
        return bin2hex($rawHash);
    }
}
