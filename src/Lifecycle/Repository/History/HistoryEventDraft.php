<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Repository\History;

use DateTimeImmutable;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\Enum\HistoryEventTypeEnum;
use Maatify\Slug\Lifecycle\Enum\RegistryRoleEnum;
use Maatify\Slug\Lifecycle\ValueObject\EntityReference;
use Maatify\Slug\Canonicalization\ValueObject\Slug;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;

/** @internal Immutable input for one append-only history row. */
final readonly class HistoryEventDraft
{
    /** Carries the event snapshot and audit metadata into the history repository. */
    public function __construct(
        public int $bindingId,
        public int $sequenceNo,
        public HistoryEventTypeEnum $eventType,
        public SlugScope $scopeSnapshot,
        public EntityReference $entitySnapshot,
        public ?Slug $slugSnapshot,
        public ?Slug $previousSlugSnapshot,
        public ?RegistryRoleEnum $claimRoleSnapshot,
        public ?RegistryRoleEnum $previousClaimRoleSnapshot,
        public ?SlugScope $relatedScope,
        public ?EntityReference $relatedEntity,
        public ?int $operationId,
        public ?string $operationKey,
        public AuditContextDTO $audit,
        public DateTimeImmutable $occurredAt,
        public ?DateTimeImmutable $originalOccurredAt = null,
    ) {}
}
