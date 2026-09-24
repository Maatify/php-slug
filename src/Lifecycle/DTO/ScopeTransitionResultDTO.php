<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\DTO;

use JsonSerializable;
use Maatify\Slug\Lifecycle\Enum\OperationTypeEnum;
use Maatify\Slug\Lifecycle\DTO\HistoryEventDTO;
use Maatify\Slug\Lifecycle\Enum\ScopeTransitionModeEnum;
use Maatify\Slug\Lifecycle\DTO\BindingDTO;
use Maatify\Slug\Canonicalization\ValueObject\Slug;

/** Immutable result of a scope transition with participant state and ordered history. */
final readonly class ScopeTransitionResultDTO implements JsonSerializable
{
    /**
     * Validates transition metadata, revisions, and ordered participant history.
     *
     * @param list<HistoryEventDTO> $historyEvents
     */
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
        if ($operationKey !== null && preg_match('/\A[a-f0-9]{32}\z/', $operationKey) !== 1) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('Operation key must be lowercase hexadecimal of length 32.');
        }
        if ($sourceRevision < 0) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('sourceRevision must be non-negative.');
        }
        if ($targetRevision < 0) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('targetRevision must be non-negative.');
        }
        self::participantHistory($historyEvents, [$sourceAfter->id, $targetAfter->id], 'historyEvents');
    }

    /**
     * @param array<int, mixed> $items
     * @param list<int> $bindingIds
     */
    private static function participantHistory(array $items, array $bindingIds, string $field): void
    {
        if (! array_is_list($items)) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException(sprintf('%s must be a list.', $field));
        }
        $lastParticipant = -1;
        $lastSequence = [];
        $lastId = [];
        foreach ($items as $item) {
            if (! is_object($item) || ! property_exists($item, 'bindingId') || ! property_exists($item, 'sequenceNo') || ! property_exists($item, 'id') || ! is_int($item->bindingId) || ! is_int($item->sequenceNo) || ! is_int($item->id)) {
                throw new \Maatify\Slug\Exception\SlugInvalidArgumentException(sprintf('%s contains an invalid history event.', $field));
            }
            $participant = array_search($item->bindingId, $bindingIds, true);
            if ($participant === false || $participant < $lastParticipant) {
                throw new \Maatify\Slug\Exception\SlugInvalidArgumentException(sprintf('%s has invalid participant ordering.', $field));
            }
            if ($participant > $lastParticipant) {
                $lastParticipant = $participant;
            }
            if (isset($lastSequence[$item->bindingId]) && ($item->sequenceNo < $lastSequence[$item->bindingId] || ($item->sequenceNo === $lastSequence[$item->bindingId] && $item->id <= $lastId[$item->bindingId]))) {
                throw new \Maatify\Slug\Exception\SlugInvalidArgumentException(sprintf('%s must be ordered within each participant.', $field));
            }
            $lastSequence[$item->bindingId] = $item->sequenceNo;
            $lastId[$item->bindingId] = $item->id;
        }
    }

    /** Returns the same transition result with replay metadata applied. */
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
