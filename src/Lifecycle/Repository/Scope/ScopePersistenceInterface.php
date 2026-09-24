<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Repository\Scope;

use Maatify\Slug\Lifecycle\DTO\BindingDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\ValueObject\EntityReference;

/** Persistence boundary for scope creation and binding read models. */
interface ScopePersistenceInterface
{
    /** Ensures and returns a scope/profile association. */
    public function ensureScope(ScopeProfileRequestDTO $request): ScopeDTO;

    /** Ensures a binding placeholder and returns its public snapshot. */
    public function ensureBindingPlaceholder(ScopeProfileRequestDTO $request, EntityReference $entity): BindingDTO;

    /** Finds a binding by identity, optionally with a persistence lock. */
    public function findBinding(ScopeProfileRequestDTO $request, EntityReference $entity, bool $forUpdate = false): ?BindingDTO;

    /** Finds a binding by persisted identifier, optionally with a persistence lock. */
    public function findBindingById(int $bindingId, bool $forUpdate = false): ?BindingDTO;
}
