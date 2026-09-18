<?php

declare(strict_types=1);

namespace Maatify\Slug\Query\Criteria;

use Maatify\Slug\Registry\DTO\BindingIdentityDTO;

final readonly class CurrentSlugCriteria
{
    public function __construct(public BindingIdentityDTO $binding) {}
}
