<?php

declare(strict_types=1);

namespace Maatify\Slug\Internal\ResultSnapshot;

use Maatify\Slug\Contract\ResultSnapshot\ResultSnapshotDecoder;
use Maatify\Slug\Contract\ResultSnapshot\ResultSnapshotEncoder;
use Maatify\Slug\DTO\AdoptionResultDTO;
use Maatify\Slug\DTO\AtomicTransferResultDTO;
use Maatify\Slug\DTO\ScopeTransitionResultDTO;
use Maatify\Slug\DTO\SlugMutationResultDTO;
use Maatify\Slug\Exception\SlugPersistenceInvariantException;
use Maatify\Slug\Profile\Contracts\SlugProfileRegistryInterface;

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
