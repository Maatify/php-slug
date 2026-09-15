<?php

declare(strict_types=1);

namespace Maatify\Slug\DTO;

use JsonSerializable;

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
        DTOAssertions::nonNegative($revision, 'revision');
        DTOAssertions::orderedHistory($historyEvents, 'historyEvents');
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
