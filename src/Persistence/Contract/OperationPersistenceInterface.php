<?php

declare(strict_types=1);

namespace Maatify\Slug\Persistence\Contract;

use Maatify\Slug\Enum\OperationTypeEnum;
use Maatify\Slug\Internal\ResultSnapshot\ResultSnapshotMetadata;
use Maatify\Slug\DTO\AdoptionResultDTO;
use Maatify\Slug\DTO\AtomicTransferResultDTO;
use Maatify\Slug\DTO\ScopeTransitionResultDTO;
use Maatify\Slug\DTO\SlugMutationResultDTO;
use Maatify\Slug\Profile\Contracts\SlugProfileRegistryInterface;

interface OperationPersistenceInterface
{
    /**
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
