<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Command;

use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;

/** Requests deactivation of a binding at an expected revision. */
final readonly class DeactivateBindingCommand
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
