<?php

declare(strict_types=1);

namespace JTranslate\Service\Adapter;

use JTranslate\Service\ActingUserProviderInterface;

/**
 * Wraps any callable returning a user id, so that a host application's existing
 * provider satisfies {@see ActingUserProviderInterface} without being made to
 * implement it.
 *
 * SionModel ships a provider with exactly this shape. Requiring it to declare our
 * interface would put a dependency arrow in the wrong direction — from the
 * application's model layer to a translation library.
 */
final class CallableActingUserProvider implements ActingUserProviderInterface
{
    /** @var callable(): (int|null) */
    private $resolve;

    /** @param callable(): (int|null) $resolve */
    public function __construct(callable $resolve)
    {
        $this->resolve = $resolve;
    }

    public function getActingUserId(): ?int
    {
        $id = ($this->resolve)();

        return null === $id ? null : (int) $id;
    }
}
