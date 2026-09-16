<?php

declare(strict_types=1);

namespace Maatify\Slug\Persistence\Contract;

use Maatify\Slug\DTO\BindingDTO;
use Maatify\Slug\DTO\ScopeDTO;
use Maatify\Slug\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Identity\EntityReference;

interface ScopePersistenceInterface
{
    public function ensureScope(ScopeProfileRequestDTO $request): ScopeDTO;

    public function ensureBindingPlaceholder(ScopeProfileRequestDTO $request, EntityReference $entity): BindingDTO;

    public function findBinding(ScopeProfileRequestDTO $request, EntityReference $entity, bool $forUpdate = false): ?BindingDTO;

    public function findBindingById(int $bindingId, bool $forUpdate = false): ?BindingDTO;
}
