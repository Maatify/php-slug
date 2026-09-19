<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Consumer\Criteria;

use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;

final readonly class ResolutionCriteria
{
    public function __construct(
        public ScopeProfileRequestDTO $scopeProfile,
        public string $decodedSegment,
    ) {}
}
