<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Command;

use DateTimeImmutable;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;

/** Requests adoption of an existing current claim, allowing an absent revision for a new binding. */
final readonly class AdoptCurrentCommand
{
    /** Validates the candidate, optional expected revision, and optional historical timing. */
    public function __construct(
        public BindingIdentityDTO $binding,
        public string $slugCandidate,
        public ?DateTimeImmutable $originalOccurredAt,
        public ?int $expectedRevision,
        public AuditContextDTO $audit,
    ) {
        CommandAssertions::nonEmpty($slugCandidate, 'slugCandidate');
        CommandAssertions::revision($expectedRevision);
        CommandAssertions::dateTime($originalOccurredAt, 'originalOccurredAt');
    }
}
