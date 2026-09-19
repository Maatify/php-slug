<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Repository\History;

use Maatify\Slug\Lifecycle\DTO\HistoryEventDTO;

interface HistoryRepositoryInterface
{
    public function append(HistoryEventDraft $draft): HistoryEventDTO;

    public function findById(int $id, bool $forUpdate = false): ?HistoryEventDTO;
}
