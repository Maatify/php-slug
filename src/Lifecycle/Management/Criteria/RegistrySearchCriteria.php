<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Management\Criteria;

use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\Enum\BindingStatusEnum;
use Maatify\Slug\Lifecycle\Enum\RegistryRoleEnum;
use Maatify\Slug\Exception\SlugInvalidArgumentException;

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
            if (preg_match('//u', $slugPrefix) !== 1) {
                throw new SlugInvalidArgumentException('slugPrefix must be valid UTF-8.');
            }
        }
    }
}
