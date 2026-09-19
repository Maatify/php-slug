<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\DTO;

use JsonException;
use JsonSerializable;
use Maatify\Slug\Lifecycle\Enum\OperationTypeEnum;
use Maatify\Slug\Lifecycle\DTO\HistoryEventDTO;
use Maatify\Slug\Lifecycle\DTO\RegistryClaimDTO;
use Maatify\Slug\Lifecycle\Enum\BindingStatusEnum;
use Maatify\Slug\Lifecycle\Enum\RegistryRoleEnum;

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
        if ($operationKey !== null && preg_match('/\A[a-f0-9]{32}\z/', $operationKey) !== 1) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('Operation key must be lowercase hexadecimal of length 32.');
        }
        if ($sourceRevision < 0) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('sourceRevision must be non-negative.');
        }
        if ($targetRevision < 0) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('targetRevision must be non-negative.');
        }
        self::participantHistory($historyEvents, [$sourceResult->after->id, $targetResult->after->id], 'historyEvents');
        if ($targetResult->before === null) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('Transfer target result requires its existing before state.');
        }
        if (($transferredClaim->role === RegistryRoleEnum::CURRENT_CANONICAL) !== ($sourceReplacementResult !== null)) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('Current transfers require a source replacement result.');
        }
        self::assertCompleteTransferHistory($sourceResult, $targetResult, $historyEvents, $transferredClaim);
        if ($sourceReplacementResult !== null && ! in_array($sourceReplacementResult->changeType->value, ['CHANGED', 'RESTORED'], true)) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('Transfer replacement must be changed or restored.');
        }
        if ($sourceReplacementResult !== null && $sourceReplacementResult->operationType !== OperationTypeEnum::ATOMIC_TRANSFER) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('Transfer replacement must use ATOMIC_TRANSFER.');
        }
        if ($sourceReplacementResult !== null && ($sourceReplacementResult->operationKey !== $operationKey || $sourceReplacementResult->replayed !== $replayed)) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('Transfer replacement metadata must match the outer transfer.');
        }
        if ($sourceReplacementResult !== null) {
            self::assertFinalReplacement($sourceResult, $sourceReplacementResult, $transferredClaim);
        }
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

    /** @param list<HistoryEventDTO> $historyEvents */
    private static function assertCompleteTransferHistory(
        BindingStateResultDTO $sourceResult,
        BindingStateResultDTO $targetResult,
        array $historyEvents,
        RegistryClaimDTO $transferredClaim,
    ): void {
        $sourceEvents = $sourceResult->historyEvents;
        $targetEvents = $targetResult->historyEvents;
        if (! self::sameHistory($historyEvents, array_merge($sourceEvents, $targetEvents))) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('Transfer history must contain complete source and target evidence.');
        }

        $sourceOut = array_values(array_filter(
            $sourceEvents,
            static fn(HistoryEventDTO $event): bool => $event->eventType->value === 'OWNERSHIP_TRANSFERRED_OUT',
        ));
        $targetIn = array_values(array_filter(
            $targetEvents,
            static fn(HistoryEventDTO $event): bool => $event->eventType->value === 'OWNERSHIP_TRANSFERRED_IN',
        ));
        if (count($sourceOut) !== 1 || count($targetIn) !== 1) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('Transfer must contain one source out event and one target in event.');
        }
        if (
            $sourceOut[0]->bindingId !== $sourceResult->after->id
            || $targetIn[0]->bindingId !== $targetResult->after->id
            || $sourceOut[0]->slugSnapshot?->value !== $transferredClaim->slug->value
            || $targetIn[0]->slugSnapshot?->value !== $transferredClaim->slug->value
            || $sourceOut[0]->claimRoleSnapshot !== $transferredClaim->role
            || $targetIn[0]->claimRoleSnapshot !== $transferredClaim->role
        ) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('Transfer events do not match the transferred claim.');
        }
    }

    private static function assertFinalReplacement(
        BindingStateResultDTO $sourceResult,
        SlugMutationResultDTO $replacement,
        RegistryClaimDTO $transferredClaim,
    ): void {
        if ($sourceResult->after->state->status === BindingStatusEnum::RELEASED) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('Current transfer source result cannot be RELEASED after replacement.');
        }
        if ($replacement->revision !== $sourceResult->revision) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('Replacement revision must equal the final source revision.');
        }
        if ($replacement->after->state->currentSlug?->value === $transferredClaim->slug->value) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('Final source replacement must remove the transferred claim.');
        }
        if (! self::sameJson($replacement->before, $sourceResult->before) || ! self::sameJson($replacement->after, $sourceResult->after)) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('Replacement before and after must be the final source evidence.');
        }
        if ($replacement->historyEvents === []) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('Replacement result requires replacement history.');
        }
        foreach ($replacement->historyEvents as $event) {
            if (! in_array($event->eventType->value, ['CHANGED', 'RESTORED'], true) || $event->bindingId !== $sourceResult->after->id) {
                throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('Replacement history must contain replacement events only.');
            }
        }
        if (! self::historyPrefix($sourceResult->historyEvents, $replacement->historyEvents)) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('Replacement history must precede transfer-out history.');
        }
    }

    /**
     * @param list<HistoryEventDTO> $left
     * @param list<HistoryEventDTO> $right
     */
    private static function sameHistory(array $left, array $right): bool
    {
        if (count($left) !== count($right)) {
            return false;
        }
        foreach ($left as $index => $event) {
            if (! self::sameJson($event, $right[$index])) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param list<HistoryEventDTO> $history
     * @param list<HistoryEventDTO> $prefix
     */
    private static function historyPrefix(array $history, array $prefix): bool
    {
        if (count($prefix) > count($history)) {
            return false;
        }
        foreach ($prefix as $index => $event) {
            if (! self::sameJson($history[$index], $event)) {
                return false;
            }
        }
        return true;
    }

    private static function sameJson(mixed $left, mixed $right): bool
    {
        try {
            return json_encode($left, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)
                === json_encode($right, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }
    }
}
