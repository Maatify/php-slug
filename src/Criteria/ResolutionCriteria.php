<?php

declare(strict_types=1);

namespace Maatify\Slug\Criteria;

use Maatify\Slug\DTO\ScopeProfileRequestDTO;

final readonly class ResolutionCriteria
{
    public function __construct(
        public ScopeProfileRequestDTO $scopeProfile,
        public string $decodedSegment,
    ) {}
}
