<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Management\Criteria;

use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;

final readonly class ScopeCriteria
{
    public function __construct(public ScopeProfileRequestDTO $scopeProfile) {}
}
