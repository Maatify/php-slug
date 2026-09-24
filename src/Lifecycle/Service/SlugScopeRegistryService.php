<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Service;

use Maatify\Slug\Lifecycle\DTO\ScopeDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\Repository\Scope\ScopePersistenceInterface;

/** Service adapter for ensuring package-owned scope/profile associations. */
final readonly class SlugScopeRegistryService implements SlugScopeRegistryInterface
{
    /** Uses the persistence boundary supplied by the engine factory. */
    public function __construct(private ScopePersistenceInterface $persistence) {}

    /** Returns an existing scope or creates the requested scope atomically. */
    public function ensureScope(ScopeProfileRequestDTO $request): ScopeDTO
    {
        return $this->persistence->ensureScope($request);
    }
}
