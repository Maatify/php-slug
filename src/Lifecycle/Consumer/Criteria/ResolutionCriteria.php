<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Consumer\Criteria;

use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;

/** Describes a non-mutating lookup resolution within a requested scope/profile. */
final readonly class ResolutionCriteria
{
    /** Preserves the decoded lookup segment for canonicality classification. */
    public function __construct(
        public ScopeProfileRequestDTO $scopeProfile,
        public string $decodedSegment,
    ) {}
}
