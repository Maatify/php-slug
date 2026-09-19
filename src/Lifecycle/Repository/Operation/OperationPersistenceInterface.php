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

    public function findByParticipant(int $bindingId, string $idempotencyKey, bool $forUpdate = false): ?OperationRecord;

    public function commitSnapshot(int $operationId, ResultSnapshotMetadata $metadata, SlugProfileRegistryInterface $profiles): void;

    /**
     * @return SlugMutationResultDTO|ScopeTransitionResultDTO|AtomicTransferResultDTO|AdoptionResultDTO
     */
    public function decodeCommitted(int $operationId, SlugProfileRegistryInterface $profiles): SlugMutationResultDTO|ScopeTransitionResultDTO|AtomicTransferResultDTO|AdoptionResultDTO;
}
