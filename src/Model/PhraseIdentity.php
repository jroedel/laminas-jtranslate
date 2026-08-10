<?php

declare(strict_types=1);

namespace JTranslate\Model;

use function bin2hex;
use function hash;
use function str_replace;

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
 * ## One normalization, and only one
 *
 * Line endings, and nothing else. Not trimmed, not case-folded, not
 * Unicode-normalized. The rule the rest of that list protects still holds — the hash
 * must identify the string the translator uses as its key, and anything that maps two
 * meaningfully different runtime strings onto one hash makes one of them permanently
 * untranslatable.
 *
 * Line endings are the one case where two strings differ in bytes and in nothing a
 * translator could act on. `Only delete an assignment if it was created by mistake!
 * …` sat in the table twice, once with `\n` and once with `\r\n`, from the same
 * template before and after its file's line endings changed. `UNIQUE (project,
 * text_domain, phrase_hash)` cannot collapse those — the strings genuinely differ and
 * the hash was doing its job — so both rows are real, both ask a translator for the
 * same sentence, and whichever one they answer, half the renders miss.
 *
 * The cost is the one that section warns about, and it is paid where it can be seen:
 * a template emitting CRLF now finds no row of its own, so it would render its source
 * text forever and never be recorded. {@see TranslationsTable::getTranslatedText()}
 * closes that by emitting a CRLF key alongside the stored one for every phrase that
 * contains a newline, so the compiled catalog answers both spellings from the one row.
 * Together those two are what make this safe; neither is safe alone.
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
        return hash(self::ALGORITHM, self::normalize($phrase), true);
    }

    /**
     * The same digest as 64 lower-case hex characters, for use as an array key.
     */
    public static function hex(string $phrase): string
    {
        return hash(self::ALGORITHM, self::normalize($phrase));
    }

    /**
     * The form of a phrase that gets hashed, and that gets stored.
     *
     * CRLF and a lone CR both become LF. Public because a phrase is *written* in this
     * form too — a stored phrase whose bytes disagreed with what its own hash was
     * computed over would be a row nothing could ever look up — and because the
     * migration that collapses the existing pairs has to reproduce it in SQL:
     *
     * ```sql
     * REPLACE(REPLACE(phrase, CHAR(13,10), CHAR(10)), CHAR(13), CHAR(10))
     * ```
     *
     * Order matters in both spellings: taking the lone CR first would turn every CRLF
     * into a blank line.
     */
    public static function normalize(string $phrase): string
    {
        return str_replace(["\r\n", "\r"], "\n", $phrase);
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
