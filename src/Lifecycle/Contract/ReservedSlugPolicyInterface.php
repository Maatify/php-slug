<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Contract;

use Maatify\Slug\Canonicalization\ValueObject\Slug;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;

/** Host-supplied policy used to reject slugs reserved outside this package. */
interface ReservedSlugPolicyInterface
{
    /** Reports whether a canonical slug is reserved for the supplied scope. */
    public function isReserved(SlugScope $scope, Slug $slug): bool;
}
