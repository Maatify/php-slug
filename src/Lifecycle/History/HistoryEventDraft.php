<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\History;

use DateTimeImmutable;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\Enum\HistoryEventTypeEnum;
use Maatify\Slug\Registry\Enum\RegistryRoleEnum;
use Maatify\Slug\Registry\Value\EntityReference;
use Maatify\Slug\Text\Value\Slug;
use Maatify\Slug\Scope\Value\SlugScope;

/** @internal Immutable input for one append-only History row. */
final readonly class HistoryEventDraft
{
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
