<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Management\Criteria;

use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;

/** Identifies a scope/profile pair for a management read. */
final readonly class ScopeCriteria
{
    /** Stores the complete scope/profile identity used by persistence. */
    public function __construct(public ScopeProfileRequestDTO $scopeProfile) {}
}
