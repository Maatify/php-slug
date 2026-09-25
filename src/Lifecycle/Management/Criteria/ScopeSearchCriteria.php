<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Management\Criteria;

use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Exception\SlugInvalidArgumentException;

/** Selects persisted package scopes by optional literal namespace prefix and profile. */
final readonly class ScopeSearchCriteria
{
    /**
     * Keeps pagination explicit and treats namespacePrefix as a literal SQL
     * prefix; wildcard characters are escaped by the PDO repository.
     */
    public function __construct(
        public PageRequest $pageRequest,
        public ?string $namespacePrefix = null,
        public ?SlugProfileKey $profileKey = null,
    ) {
        if ($namespacePrefix !== null) {
            if ($namespacePrefix === '' || preg_match('//u', $namespacePrefix) !== 1) {
                throw new SlugInvalidArgumentException('namespacePrefix must be non-empty valid UTF-8.');
            }
            if (preg_match('/[\p{Cc}\p{Cs}\p{Cf}]/u', $namespacePrefix) === 1
                || preg_match('/^[\x09-\x0D\x20\x{00A0}]|[\x09-\x0D\x20\x{00A0}]$/u', $namespacePrefix) === 1
                || mb_strlen($namespacePrefix, 'UTF-8') > 63) {
                throw new SlugInvalidArgumentException('namespacePrefix is invalid.');
            }
        }
    }
}
