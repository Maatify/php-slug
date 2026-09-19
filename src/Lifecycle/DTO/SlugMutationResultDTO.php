<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\DTO;

use JsonSerializable;
use Maatify\Slug\Lifecycle\Enum\ChangeTypeEnum;
use Maatify\Slug\Lifecycle\Enum\OperationTypeEnum;
use Maatify\Slug\Lifecycle\DTO\HistoryEventDTO;
use Maatify\Slug\Lifecycle\DTO\BindingDTO;
use Maatify\Slug\Lifecycle\DTO\RegistryClaimDTO;
use Maatify\Slug\Canonicalization\ValueObject\Slug;

final readonly class SlugMutationResultDTO implements JsonSerializable
{
    /**
     * @param list<RegistryClaimDTO> $affectedClaims
     * @param list<HistoryEventDTO> $historyEvents
     */
    public function __construct(
        public OperationTypeEnum $operationType,
        public ?string $operationKey,
        public bool $replayed,
        public ?BindingDTO $before,
        public BindingDTO $after,
        /** @var list<RegistryClaimDTO> */
        public array $affectedClaims,
        public ?Slug $previousSlug,
        public ?Slug $currentSlug,
        public ChangeTypeEnum $changeType,
        public int $revision,
        /** @var list<HistoryEventDTO> */
        public array $historyEvents,
    ) {
        if ($operationKey !== null && preg_match('/\A[a-f0-9]{32}\z/', $operationKey) !== 1) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('Operation key must be lowercase hexadecimal of length 32.');
        }
        if ($revision < 0) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('revision must be non-negative.');
        }
        self::sortedById($affectedClaims, 'affectedClaims');
        self::orderedHistory($historyEvents, 'historyEvents');
    }

    /** @param array<int, mixed> $items */
    private static function sortedById(array $items, string $field): void
    {
        if (! array_is_list($items)) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException(sprintf('%s must be a list.', $field));
        }
        $previous = null;
        foreach ($items as $item) {
            if (! is_object($item) || ! property_exists($item, 'id') || ! is_int($item->id)) {
                throw new \Maatify\Slug\Exception\SlugInvalidArgumentException(sprintf('%s contains an item without an integer id.', $field));
            }
            if ($previous !== null && $item->id <= $previous) {
                throw new \Maatify\Slug\Exception\SlugInvalidArgumentException(sprintf('%s must be strictly ordered by id.', $field));
            }
            $previous = $item->id;
        }
    }

    /** @param array<int, mixed> $items */
    private static function orderedHistory(array $items, string $field): void
    {
        if (! array_is_list($items)) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException(sprintf('%s must be a list.', $field));
        }
        $previousSequence = null;
        $previousId = null;
        foreach ($items as $item) {
            if (! is_object($item) || ! property_exists($item, 'sequenceNo') || ! property_exists($item, 'id') || ! is_int($item->sequenceNo) || ! is_int($item->id)) {
                throw new \Maatify\Slug\Exception\SlugInvalidArgumentException(sprintf('%s contains an invalid history event.', $field));
            }
            if ($previousSequence !== null && ($item->sequenceNo < $previousSequence || ($item->sequenceNo === $previousSequence && $item->id <= $previousId))) {
                throw new \Maatify\Slug\Exception\SlugInvalidArgumentException(sprintf('%s must be ordered by sequence and id.', $field));
            }
            $previousSequence = $item->sequenceNo;
            $previousId = $item->id;
        }
    }

    public function withReplayed(bool $replayed): self
    {
        return new self(
            $this->operationType,
            $this->operationKey,
            $replayed,
            $this->before,
            $this->after,
            $this->affectedClaims,
            $this->previousSlug,
            $this->currentSlug,
            $this->changeType,
            $this->revision,
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
            'before' => $this->before,
            'after' => $this->after,
            'affected_claims' => $this->affectedClaims,
            'previous_slug' => $this->previousSlug?->value,
            'current_slug' => $this->currentSlug?->value,
            'change_type' => $this->changeType->value,
            'revision' => $this->revision,
            'history_events' => $this->historyEvents,
        ];
    }
}
