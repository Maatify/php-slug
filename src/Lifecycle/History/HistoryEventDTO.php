<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\History;

use DateTimeImmutable;
use JsonSerializable;
use Maatify\Slug\Lifecycle\Enum\HistoryEventTypeEnum;
use Maatify\Slug\Registry\Enum\RegistryRoleEnum;
use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\Registry\Value\EntityReference;
use Maatify\Slug\Shared\Validation\DTOAssertions;
use Maatify\Slug\Shared\Validation\IdentityValidator;
use Maatify\Slug\Text\Value\Slug;
use Maatify\Slug\Scope\Value\SlugScope;

final readonly class HistoryEventDTO implements JsonSerializable
{
    public function __construct(
        public int $id,
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
        public ?string $operationKey,
        public ?string $actorKey,
        public ?string $reason,
        public ?string $correlationKey,
        public DateTimeImmutable $occurredAt,
        public ?DateTimeImmutable $originalOccurredAt,
    ) {
        DTOAssertions::nonNegative($id, 'id');
        DTOAssertions::nonNegative($bindingId, 'bindingId');
        DTOAssertions::nonNegative($sequenceNo, 'sequenceNo');
        DTOAssertions::operationKey($operationKey);
        if ($actorKey !== null) {
            IdentityValidator::assertAuditString($actorKey, 191, 'actorKey');
        }
        if ($reason !== null) {
            IdentityValidator::assertReason($reason);
        }
        if ($correlationKey !== null) {
            IdentityValidator::assertAuditString($correlationKey, 191, 'correlationKey');
        }

        $marker = in_array($eventType, [
            HistoryEventTypeEnum::DEACTIVATED,
            HistoryEventTypeEnum::REACTIVATED,
            HistoryEventTypeEnum::OWNERSHIP_RELEASED_ALL,
        ], true);
        $adoption = in_array($eventType, [
            HistoryEventTypeEnum::ADOPTED_CURRENT,
            HistoryEventTypeEnum::ADOPTED_HISTORICAL,
            HistoryEventTypeEnum::ADOPTED_ALIAS,
        ], true);

        if ($adoption !== ($originalOccurredAt !== null)) {
            throw new SlugInvalidArgumentException('Only adoption history events may carry originalOccurredAt.');
        }
        if ($marker) {
            if ($slugSnapshot !== null || $previousSlugSnapshot !== null || $claimRoleSnapshot !== null || $previousClaimRoleSnapshot !== null) {
                throw new SlugInvalidArgumentException('Marker history events cannot carry claim snapshots.');
            }
        } elseif ($slugSnapshot === null || $claimRoleSnapshot === null) {
            throw new SlugInvalidArgumentException('Claim history events require a slug and claim role snapshot.');
        }
        if (($previousSlugSnapshot === null) !== ($previousClaimRoleSnapshot === null)) {
            throw new SlugInvalidArgumentException('Previous slug and previous role snapshots must be paired.');
        }
        if (in_array($eventType, [
            HistoryEventTypeEnum::CHANGED,
            HistoryEventTypeEnum::RESTORED,
            HistoryEventTypeEnum::ALIAS_PROMOTED,
        ], true) && $previousSlugSnapshot === null) {
            throw new SlugInvalidArgumentException('This history event requires a previous claim snapshot.');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'binding_id' => $this->bindingId,
            'sequence_no' => $this->sequenceNo,
            'event_type' => $this->eventType->value,
            'scope_snapshot' => $this->scopeSnapshot,
            'entity_snapshot' => [
                'entity_type' => $this->entitySnapshot->entityType,
                'entity_key' => $this->entitySnapshot->entityKey,
            ],
            'slug_snapshot' => $this->slugSnapshot?->value,
            'previous_slug_snapshot' => $this->previousSlugSnapshot?->value,
            'claim_role_snapshot' => $this->claimRoleSnapshot?->value,
            'previous_claim_role_snapshot' => $this->previousClaimRoleSnapshot?->value,
            'related_scope' => $this->relatedScope,
            'related_entity' => $this->relatedEntity === null ? null : [
                'entity_type' => $this->relatedEntity->entityType,
                'entity_key' => $this->relatedEntity->entityKey,
            ],
            'operation_key' => $this->operationKey,
            'actor_key' => $this->actorKey,
            'reason' => $this->reason,
            'correlation_key' => $this->correlationKey,
            'occurred_at' => $this->occurredAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z'),
            'original_occurred_at' => $this->originalOccurredAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z'),
        ];
    }
}
