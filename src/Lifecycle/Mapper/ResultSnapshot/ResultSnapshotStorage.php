<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Mapper\ResultSnapshot;

use Maatify\Slug\Lifecycle\DTO\AdoptionResultDTO;
use Maatify\Slug\Lifecycle\DTO\AtomicTransferResultDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeTransitionResultDTO;
use Maatify\Slug\Lifecycle\DTO\SlugMutationResultDTO;
use Maatify\Slug\Lifecycle\Exception\SlugPersistenceInvariantException;
use Maatify\Slug\Lifecycle\Repository\Operation\OperationSnapshotState;
use Maatify\Slug\Lifecycle\Repository\Operation\ResultSnapshotMetadata;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistryInterface;

final class ResultSnapshotStorage
{
    /**
     * @return SlugMutationResultDTO|ScopeTransitionResultDTO|AtomicTransferResultDTO|AdoptionResultDTO
     */
    public static function decodeCommitted(
        ResultSnapshotMetadata $metadata,
        SlugProfileRegistryInterface $profiles,
    ): SlugMutationResultDTO|ScopeTransitionResultDTO|AtomicTransferResultDTO|AdoptionResultDTO {
        $decoded = ResultSnapshotDecoder::decode(
            $metadata->snapshot,
            $profiles,
            $metadata->resultType,
            $metadata->resultSchemaVersion,
            $metadata->operationType->value,
            $metadata->operationKey,
        );

        if ($decoded->operationKey !== $metadata->operationKey || ! $decoded->replayed) {
            throw new SlugPersistenceInvariantException('Committed Result Snapshot replay metadata is invalid.');
        }

        $original = $decoded->withReplayed(false);
        if (ResultSnapshotEncoder::encode($original) !== $metadata->snapshot) {
            throw new SlugPersistenceInvariantException('Stored Result Snapshot is not canonical JSON v1.');
        }

        return $decoded;
    }

    public static function assertImmutableUpdate(OperationSnapshotState $state, ResultSnapshotMetadata $metadata): void
    {
        if ($state->status !== 'IN_PROGRESS' || $state->resultSnapshot !== null || $state->completedAt !== null) {
            throw new SlugPersistenceInvariantException('A committed Result Snapshot cannot be modified.');
        }
        if ($state->operationKey !== $metadata->operationKey
            || $state->operationType->value !== $metadata->operationType->value
            || $state->resultType !== $metadata->resultType
            || $state->resultSchemaVersion !== $metadata->resultSchemaVersion) {
            throw new SlugPersistenceInvariantException('Result Snapshot metadata does not match the operation.');
        }
    }

    private function __construct() {}
}
