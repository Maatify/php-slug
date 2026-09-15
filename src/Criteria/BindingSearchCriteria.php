<?php

declare(strict_types=1);

namespace Maatify\Slug\Criteria;

use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Slug\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Enum\BindingStatusEnum;
use Maatify\Slug\Identity\IdentityValidator;

final readonly class BindingSearchCriteria
{
    public function __construct(
        public ScopeProfileRequestDTO $scopeProfile,
        public PageRequest $pageRequest,
        public ?string $entityType = null,
        public ?string $entityKeyPrefix = null,
        public ?BindingStatusEnum $status = null,
    ) {
        if ($entityType !== null) {
            IdentityValidator::assertDimension($entityType, 63, 'entityType');
        }
        if ($entityKeyPrefix !== null && $entityKeyPrefix === '') {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('entityKeyPrefix must be non-empty when supplied.');
        }
    }
}
