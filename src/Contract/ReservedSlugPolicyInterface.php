<?php

declare(strict_types=1);

namespace Maatify\Slug\Contract;

use Maatify\Slug\Identity\Slug;
use Maatify\Slug\Scope\Value\SlugScope;

interface ReservedSlugPolicyInterface
{
    public function isReserved(SlugScope $scope, Slug $slug): bool;
}
