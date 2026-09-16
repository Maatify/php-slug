<?php

declare(strict_types=1);

namespace Maatify\Slug\Criteria;

use Maatify\Slug\DTO\ScopeProfileRequestDTO;

final readonly class ScopeCriteria
{
    public function __construct(public ScopeProfileRequestDTO $scopeProfile) {}
}
