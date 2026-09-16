<?php

declare(strict_types=1);

namespace Maatify\Slug\Criteria;

use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Slug\DTO\BindingIdentityDTO;

final readonly class AliasCriteria
{
    public function __construct(
        public BindingIdentityDTO $binding,
        public PageRequest $pageRequest,
    ) {}
}
