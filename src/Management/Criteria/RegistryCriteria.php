<?php

declare(strict_types=1);

namespace Maatify\Slug\Management\Criteria;

use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Slug\Registry\DTO\BindingIdentityDTO;
use Maatify\Slug\Scope\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Registry\Enum\RegistryRoleEnum;

final readonly class RegistryCriteria
{
    public function __construct(
        public ScopeProfileRequestDTO $scopeProfile,
        public PageRequest $pageRequest,
        public ?BindingIdentityDTO $binding = null,
        public ?RegistryRoleEnum $role = null,
    ) {}
}
