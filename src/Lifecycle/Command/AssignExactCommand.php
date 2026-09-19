<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Command;

use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;

final readonly class AssignExactCommand
{
    public function __construct(
        public BindingIdentityDTO $binding,
        public string $slugCandidate,
        public ?int $expectedRevision,
        public AuditContextDTO $audit,
    ) {
        self::assertCandidate($slugCandidate);
        CommandAssertions::revision($expectedRevision);
    }

    private static function assertCandidate(string $value): void
    {
        CommandAssertions::nonEmpty($value, 'slugCandidate');
    }
}
