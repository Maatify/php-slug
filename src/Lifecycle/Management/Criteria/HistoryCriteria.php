<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Management\Criteria;

use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\Enum\HistoryEventTypeEnum;

final readonly class HistoryCriteria
{
    public function __construct(
        public BindingIdentityDTO $binding,
        public PageRequest $pageRequest,
        public ?HistoryEventTypeEnum $eventType = null,
    ) {}
}
