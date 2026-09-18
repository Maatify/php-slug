<?php

declare(strict_types=1);

namespace Maatify\Slug\Management\Criteria;

use Maatify\Slug\Scope\DTO\ScopeProfileRequestDTO;

final readonly class ScopeCriteria
{
    public function __construct(public ScopeProfileRequestDTO $scopeProfile) {}
}
