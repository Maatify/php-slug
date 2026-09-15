<?php

declare(strict_types=1);

namespace Maatify\Slug\Contract\ResultSnapshot;

use JsonException;
use Maatify\Slug\DTO\AdoptionResultDTO;
use Maatify\Slug\DTO\AtomicTransferResultDTO;
use Maatify\Slug\DTO\HistoryEventDTO;
use Maatify\Slug\DTO\ScopeTransitionResultDTO;
use Maatify\Slug\DTO\SlugMutationResultDTO;
use Maatify\Slug\Enum\OperationTypeEnum;
use Maatify\Slug\Exception\SlugPersistenceInvariantException;

final class ResultSnapshotEncoder
{
    public static function encode(
        SlugMutationResultDTO|ScopeTransitionResultDTO|AtomicTransferResultDTO|AdoptionResultDTO $result,
    ): string {
        if ($result->replayed) {
            throw new SlugPersistenceInvariantException('A replay DTO cannot be written as an original snapshot.');
        }

        [$type, $allowed] = match (true) {
            $result instanceof SlugMutationResultDTO => ['mutation', self::mutationOperations()],
            $result instanceof ScopeTransitionResultDTO => ['transition', [OperationTypeEnum::TRANSITION_SCOPE]],
            $result instanceof AtomicTransferResultDTO => ['transfer', [OperationTypeEnum::ATOMIC_TRANSFER]],
            default => ['adoption', [
                OperationTypeEnum::ADOPT_CURRENT,
                OperationTypeEnum::ADOPT_HISTORICAL,
                OperationTypeEnum::ADOPT_ALIAS,
            ]],
        };

        if (! in_array($result->operationType, $allowed, true)) {
            throw new SlugPersistenceInvariantException('Result type and operation type do not match.');
        }

        if ($result instanceof AtomicTransferResultDTO && $result->sourceReplacementResult !== null && $result->sourceReplacementResult->replayed) {
            throw new SlugPersistenceInvariantException('Nested transfer replacement cannot be replayed in an original snapshot.');
        }
        self::assertResultOperationKeys($result);

        try {
            return json_encode(
                [
                    'result_type' => $type,
                    'result_schema_version' => 1,
                    'result' => $result,
                ],
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new SlugPersistenceInvariantException('Result snapshot encoding failed.', 0, $exception);
        }
    }

    /** @return list<OperationTypeEnum> */
    private static function mutationOperations(): array
    {
        return [
            OperationTypeEnum::ASSIGN_EXACT,
            OperationTypeEnum::ASSIGN_GENERATED,
            OperationTypeEnum::CHANGE_EXACT,
            OperationTypeEnum::CHANGE_GENERATED,
            OperationTypeEnum::RESTORE_HISTORICAL,
            OperationTypeEnum::DEACTIVATE,
            OperationTypeEnum::REACTIVATE,
            OperationTypeEnum::RELEASE_CLAIM,
            OperationTypeEnum::RELEASE_ALL,
            OperationTypeEnum::ADD_ALIAS,
            OperationTypeEnum::RETIRE_ALIAS,
            OperationTypeEnum::REACTIVATE_ALIAS,
            OperationTypeEnum::PROMOTE_ALIAS,
        ];
    }

    private static function assertResultOperationKeys(
        SlugMutationResultDTO|ScopeTransitionResultDTO|AtomicTransferResultDTO|AdoptionResultDTO $result,
    ): void {
        if ($result instanceof SlugMutationResultDTO) {
            self::assertHistoryOperationKeys($result->historyEvents, $result->operationKey);
            return;
        }
        if ($result instanceof ScopeTransitionResultDTO) {
            self::assertHistoryOperationKeys($result->historyEvents, $result->operationKey);
            self::assertHistoryOperationKeys($result->sourceResult->historyEvents, $result->operationKey);
            self::assertHistoryOperationKeys($result->targetResult->historyEvents, $result->operationKey);
            return;
        }
        if ($result instanceof AtomicTransferResultDTO) {
            self::assertHistoryOperationKeys($result->historyEvents, $result->operationKey);
            self::assertHistoryOperationKeys($result->sourceResult->historyEvents, $result->operationKey);
            self::assertHistoryOperationKeys($result->targetResult->historyEvents, $result->operationKey);
            if ($result->sourceReplacementResult !== null) {
                self::assertHistoryOperationKeys($result->sourceReplacementResult->historyEvents, $result->operationKey);
            }
            return;
        }
        self::assertHistoryOperationKeys([$result->historyEvent], $result->operationKey);
    }

    /** @param list<HistoryEventDTO> $events */
    private static function assertHistoryOperationKeys(array $events, ?string $operationKey): void
    {
        foreach ($events as $event) {
            if ($event->operationKey !== $operationKey) {
                throw new SlugPersistenceInvariantException('History operation key does not match the result operation key.');
            }
        }
    }
}
