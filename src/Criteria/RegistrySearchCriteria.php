<?php

declare(strict_types=1);

namespace Maatify\Slug\Criteria;

use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Slug\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Enum\BindingStatusEnum;
use Maatify\Slug\Enum\RegistryRoleEnum;

final readonly class RegistrySearchCriteria
{
    public function __construct(
        public ScopeProfileRequestDTO $scopeProfile,
        public PageRequest $pageRequest,
        public ?string $slugPrefix = null,
        public ?RegistryRoleEnum $role = null,
        public ?BindingStatusEnum $bindingStatus = null,
    ) {
        if ($slugPrefix !== null && $slugPrefix === '') {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('slugPrefix must be non-empty when supplied.');
        }
    }
}
