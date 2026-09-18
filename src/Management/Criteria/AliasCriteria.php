<?php

declare(strict_types=1);

namespace Maatify\Slug\Management\Criteria;

use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Slug\Registry\DTO\BindingIdentityDTO;

final readonly class AliasCriteria
{
    public function __construct(
        public BindingIdentityDTO $binding,
        public PageRequest $pageRequest,
    ) {}
}
