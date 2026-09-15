<?php

declare(strict_types=1);

namespace Maatify\Slug\Command;

use DateTimeImmutable;
use Maatify\Slug\DTO\AuditContextDTO;
use Maatify\Slug\DTO\BindingIdentityDTO;

final readonly class AdoptCurrentCommand
{
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
