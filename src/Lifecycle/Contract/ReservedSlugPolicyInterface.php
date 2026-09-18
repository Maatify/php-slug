<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Contract;

use Maatify\Slug\Text\Value\Slug;
use Maatify\Slug\Scope\Value\SlugScope;

interface ReservedSlugPolicyInterface
{
    public function isReserved(SlugScope $scope, Slug $slug): bool;
}
