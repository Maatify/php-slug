<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Criteria;

use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;

/** Identifies the binding whose current claim is requested. */
final readonly class CurrentSlugCriteria
{
    /** Stores the complete binding identity used for the lookup. */
    public function __construct(public BindingIdentityDTO $binding) {}
}
