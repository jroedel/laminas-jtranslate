<?php

declare(strict_types=1);

namespace JTranslate\Service\Adapter;

use JTranslate\Service\UserDirectoryInterface;

use function is_array;

/**
 * Wraps any callable returning users keyed by id, so that a host application's user
 * table satisfies {@see UserDirectoryInterface} without implementing it.
 *
 * @see CallableActingUserProvider for why the adapter lives here rather than there.
 */
final class CallableUserDirectory implements UserDirectoryInterface
{
    /** @var callable(): mixed */
    private $resolve;

    /** @param callable(): mixed $resolve */
    public function __construct(callable $resolve)
    {
        $this->resolve = $resolve;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getUsers(): array
    {
        $users = ($this->resolve)();

        //a directory that cannot answer is treated as empty rather than fatal: the
        //only consumer is a display column in the admin listing
        return is_array($users) ? $users : [];
    }
}
