<?php

declare(strict_types=1);

namespace Maatify\Slug\Criteria;

use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Slug\DTO\BindingIdentityDTO;
use Maatify\Slug\Enum\HistoryEventTypeEnum;

final readonly class HistoryCriteria
{
    public function __construct(
        public BindingIdentityDTO $binding,
        public PageRequest $pageRequest,
        public ?HistoryEventTypeEnum $eventType = null,
    ) {}
}
