<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Service;

use Maatify\Slug\Lifecycle\DTO\ScopeDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;

interface SlugScopeRegistryInterface
{
    public function ensureScope(ScopeProfileRequestDTO $request): ScopeDTO;
}
