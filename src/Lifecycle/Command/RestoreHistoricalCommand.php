<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Command;

use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;

/** Requests restoration of a historical claim as current at an expected revision. */
final readonly class RestoreHistoricalCommand
{
    /** Validates the historical candidate and optimistic-lock revision. */
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
