<?php

declare(strict_types=1);

namespace Maatify\Slug\Criteria;

use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Slug\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Enum\BindingStatusEnum;
use Maatify\Slug\Exception\SlugInvalidArgumentException;
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
        if ($entityKeyPrefix !== null) {
            self::assertSearchPrefix($entityKeyPrefix);
        }
    }

    private static function assertSearchPrefix(string $value): void
    {
        if ($value === '' || preg_match('//u', $value) !== 1) {
            throw new SlugInvalidArgumentException('entityKeyPrefix must be non-empty valid UTF-8.');
        }
        if (preg_match('/[\p{Cc}\p{Cs}\p{Cf}]/u', $value) === 1 || str_contains($value, '/')) {
            throw new SlugInvalidArgumentException('entityKeyPrefix contains a forbidden character.');
        }
        if (preg_match('/^[\x09-\x0D\x20\x{00A0}]|[\x09-\x0D\x20\x{00A0}]$/u', $value) === 1) {
            throw new SlugInvalidArgumentException('entityKeyPrefix has forbidden boundary whitespace.');
        }
        if (mb_strlen($value, 'UTF-8') > 191) {
            throw new SlugInvalidArgumentException('entityKeyPrefix exceeds its maximum length.');
        }
    }
}
