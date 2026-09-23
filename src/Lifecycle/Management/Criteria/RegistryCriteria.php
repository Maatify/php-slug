<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Management\Criteria;

use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\Enum\RegistryRoleEnum;

/** Selects registry claims within a scope, optionally by binding and role. */
final readonly class RegistryCriteria
{
    /** Preserves the scope/profile boundary required for registry reads. */
    public function __construct(
        public ScopeProfileRequestDTO $scopeProfile,
        public PageRequest $pageRequest,
        public ?BindingIdentityDTO $binding = null,
        public ?RegistryRoleEnum $role = null,
    ) {}
}
