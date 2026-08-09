<?php

declare(strict_types=1);

namespace JTranslate\Service;

/**
 * The users a translation can be attributed to.
 *
 * Only the admin listing needs this: it shows who last touched each locale of each
 * phrase. It replaces a hard `use JUser\Model\UserTable`, which coupled this module
 * to one specific user implementation for the sake of one display column.
 */
interface UserDirectoryInterface
{
    /**
     * Every user, keyed by user id.
     *
     * The value shape is deliberately loose because the only consumer is the admin
     * template, which reads `['username']` and tolerates its absence. An
     * implementation that has no username for a user should omit the key rather than
     * invent one.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getUsers(): array;
}
