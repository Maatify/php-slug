<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Command;

use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;

/** Requests exact assignment of a candidate, with null revision meaning no prior binding state. */
final readonly class AssignExactCommand
{
    /** Validates the non-empty candidate and optional optimistic-lock revision. */
    public function __construct(
        public BindingIdentityDTO $binding,
        public string $slugCandidate,
        public ?int $expectedRevision,
        public AuditContextDTO $audit,
    ) {
        self::assertCandidate($slugCandidate);
        CommandAssertions::revision($expectedRevision);
    }

    /** Keeps candidate validation explicit at the command boundary. */
    private static function assertCandidate(string $value): void
    {
        CommandAssertions::nonEmpty($value, 'slugCandidate');
    }
}
