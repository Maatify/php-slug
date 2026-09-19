<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Service;

use Maatify\Slug\Lifecycle\DTO\ScopeDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\Repository\Scope\ScopePersistenceInterface;

final readonly class SlugScopeRegistryService implements SlugScopeRegistryInterface
{
    public function __construct(private ScopePersistenceInterface $persistence) {}

    public function ensureScope(ScopeProfileRequestDTO $request): ScopeDTO
    {
        return $this->persistence->ensureScope($request);
    }
}
