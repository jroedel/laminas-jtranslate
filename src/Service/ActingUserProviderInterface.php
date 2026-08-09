<?php

declare(strict_types=1);

namespace JTranslate\Service;

/**
 * Who is writing, at the moment of writing.
 *
 * JTranslate stamps `modified_by` on every translation row it touches. It used to
 * take that from `SionModel\Service\ActingUserProviderInterface`, which meant a
 * translation library could not be installed without another application's model
 * layer. This is the same one-method contract, owned here.
 *
 * Resolve the identity *at call time*, never at construction: the acting user can
 * change mid-request (a magic-link login is observed by writes that follow it), and
 * an implementation that caches the id at construction reintroduces the dependency
 * cycle that eager identity resolution caused across these libraries.
 */
interface ActingUserProviderInterface
{
    /** Null when nobody is authenticated — an anonymous write is still a write. */
    public function getActingUserId(): ?int;
}
