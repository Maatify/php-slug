<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Service;

use Maatify\Slug\Lifecycle\DTO\ScopeDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;

/** Ensures package-owned persistence scopes for a profile and scope identity. */
interface SlugScopeRegistryInterface
{
    /** Returns an existing matching scope or creates it atomically. */
    public function ensureScope(ScopeProfileRequestDTO $request): ScopeDTO;
}
