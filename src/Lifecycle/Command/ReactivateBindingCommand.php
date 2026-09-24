<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Command;

use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;

/** Requests reactivation of an inactive binding at an expected revision. */
final readonly class ReactivateBindingCommand
{
    /** Validates the optimistic-lock revision. */
    public function __construct(
        public BindingIdentityDTO $binding,
        public int $expectedRevision,
        public AuditContextDTO $audit,
    ) {
        CommandAssertions::revision($expectedRevision);
    }
}
