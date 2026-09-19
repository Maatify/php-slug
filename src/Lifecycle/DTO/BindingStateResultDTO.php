<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\DTO;

use JsonSerializable;
use Maatify\Slug\Lifecycle\DTO\HistoryEventDTO;
use Maatify\Slug\Lifecycle\DTO\BindingDTO;

final readonly class BindingStateResultDTO implements JsonSerializable
{
    /** @param list<HistoryEventDTO> $historyEvents */
    public function __construct(
        public ?BindingDTO $before,
        public BindingDTO $after,
        public bool $mutated,
        public int $revision,
        public array $historyEvents,
    ) {
        if ($revision < 0) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('revision must be non-negative.');
        }
        self::orderedHistory($historyEvents, 'historyEvents');
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

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'before' => $this->before,
            'after' => $this->after,
            'mutated' => $this->mutated,
            'revision' => $this->revision,
            'history_events' => $this->historyEvents,
        ];
    }
}
