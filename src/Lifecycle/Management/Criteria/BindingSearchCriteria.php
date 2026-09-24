<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Management\Criteria;

use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\Enum\BindingStatusEnum;
use Maatify\Slug\Exception\SlugInvalidArgumentException;

/** Selects bindings in a scope using optional entity and lifecycle filters. */
final readonly class BindingSearchCriteria
{
    /** Validates optional entity identifiers and prefixes before they reach SQL search parameters. */
    public function __construct(
        public ScopeProfileRequestDTO $scopeProfile,
        public PageRequest $pageRequest,
        public ?string $entityType = null,
        public ?string $entityKeyPrefix = null,
        public ?BindingStatusEnum $status = null,
    ) {
        if ($entityType !== null) {
            if ($entityType === '' || preg_match('//u', $entityType) !== 1 || preg_match('/[\p{Cc}\p{Cs}\p{Cf}]/u', $entityType) === 1 || strpbrk($entityType, '/\\') !== false || preg_match('/^[\x09-\x0D\x20\x{00A0}]|[\x09-\x0D\x20\x{00A0}]$/u', $entityType) === 1 || mb_strlen($entityType, 'UTF-8') > 63) {
                throw new SlugInvalidArgumentException('entityType is invalid.');
            }
        }
        if ($entityKeyPrefix !== null) {
            self::assertSearchPrefix($entityKeyPrefix);
        }
    }

    /** Enforces the bounded, path-safe prefix contract used by binding searches. */
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
