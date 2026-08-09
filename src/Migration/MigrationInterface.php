<?php

declare(strict_types=1);

namespace JTranslate\Migration;

use Laminas\Db\Adapter\AdapterInterface;

/**
 * One irreversible, forward-only step in bringing a database up to what this
 * version of the library expects.
 *
 * Forward-only is deliberate. A `down()` nobody ever runs is a liability: it looks
 * like a safety net and is never tested. Reversing a change here means writing the
 * next migration.
 */
interface MigrationInterface
{
    /**
     * A stable identifier, recorded in the tracking table once applied.
     *
     * Never change one after release. The tracking table keys on it, so a renamed
     * migration is an unapplied migration and runs again.
     */
    public function name(): string;

    /** One line, shown by `jtranslate:migrate --status`. */
    public function describe(): string;

    /**
     * The statements this migration would run, in order, ready to execute.
     *
     * Returning SQL rather than executing it is what makes `--pretend` honest, and
     * it is not a stylistic choice: **the web application's database user
     * deliberately lacks DDL rights**, so on a real deployment the schema step is
     * handed to somebody holding different credentials. A migration that could only
     * execute itself would be unusable there.
     *
     * The adapter is passed because a data migration has to look before it writes —
     * seeding means "insert what is missing", which cannot be expressed without
     * reading first. Implementations must not perform writes here.
     *
     * @param array<string, mixed> $config the resolved `jtranslate` config
     * @return list<array{sql: string, parameters: list<mixed>}>
     */
    public function statements(AdapterInterface $db, array $config): array;
}
