<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Contract;

use Maatify\Slug\Canonicalization\ValueObject\Slug;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;

interface ReservedSlugPolicyInterface
{
    public function isReserved(SlugScope $scope, Slug $slug): bool;
}
