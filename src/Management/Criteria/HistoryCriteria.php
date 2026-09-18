<?php

declare(strict_types=1);

namespace Maatify\Slug\Management\Criteria;

use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Slug\Registry\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\Enum\HistoryEventTypeEnum;

final readonly class HistoryCriteria
{
    public function __construct(
        public BindingIdentityDTO $binding,
        public PageRequest $pageRequest,
        public ?HistoryEventTypeEnum $eventType = null,
    ) {}
}
