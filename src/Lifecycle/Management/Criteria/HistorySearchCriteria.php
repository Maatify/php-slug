<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Management\Criteria;

use DateTimeImmutable;
use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\Lifecycle\Command\CommandAssertions;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\Enum\HistoryEventTypeEnum;

/** Selects package history by exact persisted scope snapshot, event type, and UTC time window. */
final readonly class HistorySearchCriteria
{
    /**
     * Validates the package UTC DATETIME(6) range and the half-open window
     * `[occurredFromInclusive, occurredUntilExclusive)` when both bounds exist.
     */
    public function __construct(
        public PageRequest $pageRequest,
        public ?ScopeProfileRequestDTO $scopeProfile = null,
        public ?HistoryEventTypeEnum $eventType = null,
        public ?DateTimeImmutable $occurredFromInclusive = null,
        public ?DateTimeImmutable $occurredUntilExclusive = null,
    ) {
        CommandAssertions::dateTime($occurredFromInclusive, 'occurredFromInclusive');
        CommandAssertions::dateTime($occurredUntilExclusive, 'occurredUntilExclusive');
        if ($occurredFromInclusive !== null && $occurredUntilExclusive !== null
            && $occurredFromInclusive->setTimezone(new \DateTimeZone('UTC')) >= $occurredUntilExclusive->setTimezone(new \DateTimeZone('UTC'))) {
            throw new SlugInvalidArgumentException('occurredFromInclusive must be before occurredUntilExclusive.');
        }
    }
}
