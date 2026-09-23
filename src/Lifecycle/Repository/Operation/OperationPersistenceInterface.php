<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Repository\Operation;

use Maatify\Slug\Lifecycle\Enum\OperationTypeEnum;
use Maatify\Slug\Lifecycle\Repository\Operation\ResultSnapshotMetadata;
use Maatify\Slug\Lifecycle\DTO\AdoptionResultDTO;
use Maatify\Slug\Lifecycle\DTO\AtomicTransferResultDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeTransitionResultDTO;
use Maatify\Slug\Lifecycle\DTO\SlugMutationResultDTO;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistryInterface;

/** Coordinates durable idempotency reservations and committed result snapshots. */
interface OperationPersistenceInterface
{
    /**
     * Must participate in a caller-owned transaction that also commits the
     * final Result Snapshot. The repository never commits IN_PROGRESS alone.
     *
     * @param list<OperationParticipant> $participants
     */
    public function reserve(
        string $operationKey,
        OperationTypeEnum $operationType,
        string $requestFingerprint,
        string $resultType,
        int $resultSchemaVersion,
        array $participants,
    ): OperationReservation;

    /** Returns a participant's existing operation, optionally with a row lock. */
    public function findByParticipant(int $bindingId, string $idempotencyKey, bool $forUpdate = false): ?OperationRecord;

    /** Commits the final immutable result snapshot within the caller's transaction. */
    public function commitSnapshot(int $operationId, ResultSnapshotMetadata $metadata, SlugProfileRegistryInterface $profiles): void;

    /**
     * Decodes a committed snapshot into its typed replay result.
     *
     * @return SlugMutationResultDTO|ScopeTransitionResultDTO|AtomicTransferResultDTO|AdoptionResultDTO
     */
    public function decodeCommitted(int $operationId, SlugProfileRegistryInterface $profiles): SlugMutationResultDTO|ScopeTransitionResultDTO|AtomicTransferResultDTO|AdoptionResultDTO;
}
