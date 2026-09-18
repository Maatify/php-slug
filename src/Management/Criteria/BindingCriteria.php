<?php

declare(strict_types=1);

namespace Maatify\Slug\Management\Criteria;

use Maatify\Slug\Registry\DTO\BindingIdentityDTO;

final readonly class BindingCriteria
{
    public function __construct(public BindingIdentityDTO $binding) {}
}
