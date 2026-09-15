<?php

declare(strict_types=1);

namespace Maatify\Slug\Criteria;

use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Slug\DTO\BindingIdentityDTO;
use Maatify\Slug\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Enum\RegistryRoleEnum;

final readonly class RegistryCriteria
{
    public function __construct(
        public ScopeProfileRequestDTO $scopeProfile,
        public PageRequest $pageRequest,
        public ?BindingIdentityDTO $binding = null,
        public ?RegistryRoleEnum $role = null,
    ) {
    }
}
