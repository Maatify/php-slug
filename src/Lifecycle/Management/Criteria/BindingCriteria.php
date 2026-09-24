<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Management\Criteria;

use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;

/** Identifies one binding for a management read. */
final readonly class BindingCriteria
{
    /** Stores the complete binding identity used by the repository. */
    public function __construct(public BindingIdentityDTO $binding) {}
}
