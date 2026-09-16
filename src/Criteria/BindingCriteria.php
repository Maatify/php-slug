<?php

declare(strict_types=1);

namespace Maatify\Slug\Criteria;

use Maatify\Slug\DTO\BindingIdentityDTO;

final readonly class BindingCriteria
{
    public function __construct(public BindingIdentityDTO $binding) {}
}
