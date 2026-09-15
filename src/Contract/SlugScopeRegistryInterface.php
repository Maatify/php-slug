<?php

declare(strict_types=1);

namespace Maatify\Slug\Contract;

use Maatify\Slug\DTO\ScopeDTO;
use Maatify\Slug\DTO\ScopeProfileRequestDTO;

interface SlugScopeRegistryInterface
{
    public function ensureScope(ScopeProfileRequestDTO $request): ScopeDTO;
}
