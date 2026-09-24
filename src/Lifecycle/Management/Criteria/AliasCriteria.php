<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Management\Criteria;

use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;

/** Selects one binding's aliases and the requested result page. */
final readonly class AliasCriteria
{
    /** Keeps pagination explicit at the management-read boundary. */
    public function __construct(
        public BindingIdentityDTO $binding,
        public PageRequest $pageRequest,
    ) {}
}
