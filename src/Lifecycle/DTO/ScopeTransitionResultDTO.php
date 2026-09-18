<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\DTO;

use JsonSerializable;
use Maatify\Slug\Lifecycle\Enum\OperationTypeEnum;
use Maatify\Slug\Lifecycle\History\HistoryEventDTO;
use Maatify\Slug\Lifecycle\Enum\ScopeTransitionModeEnum;
use Maatify\Slug\Registry\DTO\BindingDTO;
use Maatify\Slug\Shared\Validation\DTOAssertions;
use Maatify\Slug\Text\Value\Slug;

final readonly class ScopeTransitionResultDTO implements JsonSerializable
{
    /** @param list<HistoryEventDTO> $historyEvents */
    public function __construct(
        public OperationTypeEnum $operationType,
        public ?string $operationKey,
        public bool $replayed,
        public ScopeTransitionModeEnum $mode,
        public bool $targetCreated,
        public BindingStateResultDTO $sourceResult,
        public BindingStateResultDTO $targetResult,
        public ?BindingDTO $sourceBefore,
        public BindingDTO $sourceAfter,
        public ?BindingDTO $targetBefore,
        public BindingDTO $targetAfter,
        public ?Slug $sourceClaim,
        public ?Slug $targetClaim,
        public int $sourceRevision,
        public int $targetRevision,
        public array $historyEvents,
    ) {
        if ($operationType !== OperationTypeEnum::TRANSITION_SCOPE) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('Transition result requires TRANSITION_SCOPE.');
        }
        DTOAssertions::operationKey($operationKey);
        DTOAssertions::nonNegative($sourceRevision, 'sourceRevision');
        DTOAssertions::nonNegative($targetRevision, 'targetRevision');
        DTOAssertions::participantHistory($historyEvents, [$sourceAfter->id, $targetAfter->id], 'historyEvents');
    }

    public function withReplayed(bool $replayed): self
    {
        return new self(
            $this->operationType,
            $this->operationKey,
            $replayed,
            $this->mode,
            $this->targetCreated,
            $this->sourceResult,
            $this->targetResult,
            $this->sourceBefore,
            $this->sourceAfter,
            $this->targetBefore,
            $this->targetAfter,
            $this->sourceClaim,
            $this->targetClaim,
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
            'mode' => $this->mode->value,
            'target_created' => $this->targetCreated,
            'source_result' => $this->sourceResult,
            'target_result' => $this->targetResult,
            'source_before' => $this->sourceBefore,
            'source_after' => $this->sourceAfter,
            'target_before' => $this->targetBefore,
            'target_after' => $this->targetAfter,
            'source_claim' => $this->sourceClaim?->value,
            'target_claim' => $this->targetClaim?->value,
            'source_revision' => $this->sourceRevision,
            'target_revision' => $this->targetRevision,
            'history_events' => $this->historyEvents,
        ];
    }
}
