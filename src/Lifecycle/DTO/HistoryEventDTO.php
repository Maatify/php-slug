<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\DTO;

use DateTimeImmutable;
use JsonSerializable;
use Maatify\Slug\Lifecycle\Enum\HistoryEventTypeEnum;
use Maatify\Slug\Lifecycle\Enum\RegistryRoleEnum;
use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\Lifecycle\ValueObject\EntityReference;
use Maatify\Slug\Canonicalization\ValueObject\Slug;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;

/** Immutable append-only lifecycle event snapshot with participant and audit context. */
final readonly class HistoryEventDTO implements JsonSerializable
{
    /** Validates identifiers, timestamps, role snapshots, and event-specific invariants. */
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
        self::nonNegative($id, 'id');
        self::nonNegative($bindingId, 'bindingId');
        self::nonNegative($sequenceNo, 'sequenceNo');
        self::operationKey($operationKey);
        if ($actorKey !== null) {
            self::auditString($actorKey, 191, 'actorKey');
        }
        if ($reason !== null) {
            self::reason($reason);
        }
        if ($correlationKey !== null) {
            self::auditString($correlationKey, 191, 'correlationKey');
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

    /** Rejects negative identifiers and sequence values used by persisted history. */
    private static function nonNegative(int $value, string $field): void
    {
        if ($value < 0) {
            throw new SlugInvalidArgumentException(sprintf('%s must be non-negative.', $field));
        }
    }

    /** Validates the optional lowercase hexadecimal idempotency key format. */
    private static function operationKey(?string $value): void
    {
        if ($value !== null && preg_match('/\A[a-f0-9]{32}\z/', $value) !== 1) {
            throw new SlugInvalidArgumentException('Operation key must be lowercase hexadecimal of length 32.');
        }
    }

    /** Validates bounded audit metadata without permitting controls or path separators. */
    private static function auditString(string $value, int $maxCodePoints, string $field): void
    {
        if ($value === '' || preg_match('//u', $value) !== 1) {
            throw new SlugInvalidArgumentException(sprintf('%s must be non-empty valid UTF-8.', $field));
        }
        if (preg_match('/[\p{Cc}\p{Cs}\p{Cf}]/u', $value) === 1 || strpbrk($value, '/\\') !== false) {
            throw new SlugInvalidArgumentException(sprintf('%s contains a forbidden character.', $field));
        }
        if (preg_match('/^[\x09-\x0D\x20\x{00A0}]|[\x09-\x0D\x20\x{00A0}]$/u', $value) === 1) {
            throw new SlugInvalidArgumentException(sprintf('%s has forbidden boundary whitespace.', $field));
        }
        if (mb_strlen($value, 'UTF-8') > $maxCodePoints) {
            throw new SlugInvalidArgumentException(sprintf('%s exceeds its maximum length.', $field));
        }
    }

    /** Validates free-form history reasons while retaining UTF-8 and control limits. */
    private static function reason(string $value, int $maxCodePoints = 500, string $field = 'reason'): void
    {
        if ($value === '' || preg_match('//u', $value) !== 1) {
            throw new SlugInvalidArgumentException(sprintf('%s must be non-empty valid UTF-8.', $field));
        }
        if (preg_match('/[\p{Cc}\p{Cs}\p{Cf}]/u', $value) === 1) {
            throw new SlugInvalidArgumentException(sprintf('%s contains a forbidden character.', $field));
        }
        if (mb_strlen($value, 'UTF-8') > $maxCodePoints) {
            throw new SlugInvalidArgumentException(sprintf('%s exceeds its maximum length.', $field));
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
