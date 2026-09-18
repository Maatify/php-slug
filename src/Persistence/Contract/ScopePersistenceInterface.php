<?php

declare(strict_types=1);

namespace Maatify\Slug\Persistence\Contract;

use Maatify\Slug\Registry\DTO\BindingDTO;
use Maatify\Slug\Scope\DTO\ScopeDTO;
use Maatify\Slug\Scope\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Registry\Value\EntityReference;

interface ScopePersistenceInterface
{
    public function ensureScope(ScopeProfileRequestDTO $request): ScopeDTO;

    public function ensureBindingPlaceholder(ScopeProfileRequestDTO $request, EntityReference $entity): BindingDTO;

    public function findBinding(ScopeProfileRequestDTO $request, EntityReference $entity, bool $forUpdate = false): ?BindingDTO;

    public function findBindingById(int $bindingId, bool $forUpdate = false): ?BindingDTO;
}
