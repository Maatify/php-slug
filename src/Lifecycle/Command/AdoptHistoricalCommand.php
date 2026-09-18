<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Command;

use DateTimeImmutable;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Registry\DTO\BindingIdentityDTO;

final readonly class AdoptHistoricalCommand
{
    public function __construct(
        public BindingIdentityDTO $binding,
        public string $slugCandidate,
        public ?DateTimeImmutable $originalOccurredAt,
        public int $expectedRevision,
        public AuditContextDTO $audit,
    ) {
        CommandAssertions::nonEmpty($slugCandidate, 'slugCandidate');
        CommandAssertions::revision($expectedRevision);
        CommandAssertions::dateTime($originalOccurredAt, 'originalOccurredAt');
    }
}
