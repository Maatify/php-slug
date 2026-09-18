<?php

declare(strict_types=1);

namespace Maatify\Slug\Management\Criteria;

use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Slug\Scope\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Registry\Enum\BindingStatusEnum;
use Maatify\Slug\Registry\Enum\RegistryRoleEnum;
use Maatify\Slug\Shared\Validation\IdentityValidator;

final readonly class RegistrySearchCriteria
{
    public function __construct(
        public ScopeProfileRequestDTO $scopeProfile,
        public PageRequest $pageRequest,
        public ?string $slugPrefix = null,
        public ?RegistryRoleEnum $role = null,
        public ?BindingStatusEnum $bindingStatus = null,
    ) {
        if ($slugPrefix !== null) {
            IdentityValidator::assertValidUtf8($slugPrefix, 'slugPrefix');
        }
    }
}
