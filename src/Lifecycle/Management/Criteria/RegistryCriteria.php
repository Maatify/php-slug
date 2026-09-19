<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Management\Criteria;

use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\Enum\RegistryRoleEnum;

final readonly class RegistryCriteria
{
    public function __construct(
        public ScopeProfileRequestDTO $scopeProfile,
        public PageRequest $pageRequest,
        public ?BindingIdentityDTO $binding = null,
        public ?RegistryRoleEnum $role = null,
    ) {}
}
