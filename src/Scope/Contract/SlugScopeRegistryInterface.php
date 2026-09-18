<?php

declare(strict_types=1);

namespace Maatify\Slug\Scope\Contract;

use Maatify\Slug\Scope\DTO\ScopeDTO;
use Maatify\Slug\Scope\DTO\ScopeProfileRequestDTO;

interface SlugScopeRegistryInterface
{
    public function ensureScope(ScopeProfileRequestDTO $request): ScopeDTO;
}
