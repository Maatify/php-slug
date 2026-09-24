<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Repository\History;

use Maatify\Slug\Lifecycle\DTO\HistoryEventDTO;

/** Persists and retrieves append-only lifecycle history events. */
interface HistoryRepositoryInterface
{
    /** Appends one validated history draft and returns its persisted snapshot. */
    public function append(HistoryEventDraft $draft): HistoryEventDTO;

    /** Returns one history event, optionally acquiring a row lock. */
    public function findById(int $id, bool $forUpdate = false): ?HistoryEventDTO;
}
