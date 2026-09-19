<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Criteria;

use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;

final readonly class CurrentSlugCriteria
{
    public function __construct(public BindingIdentityDTO $binding) {}
}
