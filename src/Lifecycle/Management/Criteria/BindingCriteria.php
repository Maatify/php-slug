<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Management\Criteria;

use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;

final readonly class BindingCriteria
{
    public function __construct(public BindingIdentityDTO $binding) {}
}
