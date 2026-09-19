<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Repository\Scope;

use Maatify\Slug\Lifecycle\DTO\BindingDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\ValueObject\EntityReference;

interface ScopePersistenceInterface
{
    public function ensureScope(ScopeProfileRequestDTO $request): ScopeDTO;

    public function ensureBindingPlaceholder(ScopeProfileRequestDTO $request, EntityReference $entity): BindingDTO;

    public function findBinding(ScopeProfileRequestDTO $request, EntityReference $entity, bool $forUpdate = false): ?BindingDTO;

    public function findBindingById(int $bindingId, bool $forUpdate = false): ?BindingDTO;
}
