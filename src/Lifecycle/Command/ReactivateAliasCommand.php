<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Command;

use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;

/** Requests reactivation of an alias claim at an expected revision. */
final readonly class ReactivateAliasCommand
{
    /** Validates the alias candidate and optimistic-lock revision. */
    public function __construct(
        public BindingIdentityDTO $binding,
        public string $slugCandidate,
        public int $expectedRevision,
        public AuditContextDTO $audit,
    ) {
        CommandAssertions::nonEmpty($slugCandidate, 'slugCandidate');
        CommandAssertions::revision($expectedRevision);
    }
}
