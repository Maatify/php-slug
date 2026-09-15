<?php

declare(strict_types=1);

namespace Maatify\Slug\DTO;

use JsonSerializable;
use Maatify\Slug\Enum\OperationTypeEnum;
use Maatify\Slug\Enum\RegistryRoleEnum;

final readonly class AtomicTransferResultDTO implements JsonSerializable
{
    /** @param list<HistoryEventDTO> $historyEvents */
    public function __construct(
        public OperationTypeEnum $operationType,
        public ?string $operationKey,
        public bool $replayed,
        public BindingStateResultDTO $sourceResult,
        public BindingStateResultDTO $targetResult,
        public RegistryClaimDTO $transferredClaim,
        public ?SlugMutationResultDTO $sourceReplacementResult,
        public int $sourceRevision,
        public int $targetRevision,
        public array $historyEvents,
    ) {
        if ($operationType !== OperationTypeEnum::ATOMIC_TRANSFER) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('Transfer result requires ATOMIC_TRANSFER.');
        }
        DTOAssertions::operationKey($operationKey);
        DTOAssertions::nonNegative($sourceRevision, 'sourceRevision');
        DTOAssertions::nonNegative($targetRevision, 'targetRevision');
        DTOAssertions::participantHistory($historyEvents, [$sourceResult->after->id, $targetResult->after->id], 'historyEvents');
        if (($transferredClaim->role === RegistryRoleEnum::CURRENT_CANONICAL) !== ($sourceReplacementResult !== null)) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('Current transfers require a source replacement result.');
        }
        if ($sourceReplacementResult !== null && ! in_array($sourceReplacementResult->changeType->value, ['CHANGED', 'RESTORED'], true)) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('Transfer replacement must be changed or restored.');
        }
        if ($sourceReplacementResult !== null && $sourceReplacementResult->operationType !== OperationTypeEnum::ATOMIC_TRANSFER) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('Transfer replacement must use ATOMIC_TRANSFER.');
        }
        if ($sourceReplacementResult !== null && ($sourceReplacementResult->operationKey !== $operationKey || $sourceReplacementResult->replayed !== $replayed)) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('Transfer replacement metadata must match the outer transfer.');
        }
    }

    public function withReplayed(bool $replayed): self
    {
        return new self(
            $this->operationType,
            $this->operationKey,
            $replayed,
            $this->sourceResult,
            $this->targetResult,
            $this->transferredClaim,
            $this->sourceReplacementResult?->withReplayed($replayed),
            $this->sourceRevision,
            $this->targetRevision,
            $this->historyEvents,
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'operation_type' => $this->operationType->value,
            'operation_key' => $this->operationKey,
            'replayed' => $this->replayed,
            'source_result' => $this->sourceResult,
            'target_result' => $this->targetResult,
            'transferred_claim' => $this->transferredClaim,
            'source_replacement_result' => $this->sourceReplacementResult,
            'source_revision' => $this->sourceRevision,
            'target_revision' => $this->targetRevision,
            'history_events' => $this->historyEvents,
        ];
    }
}
