<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Reserved;

use Maatify\Slug\Lifecycle\Contract\ReservedSlugPolicyInterface;
use Maatify\Slug\Text\Value\Slug;
use Maatify\Slug\Scope\Value\SlugScope;

/** Internal pure adapter around the package-owned reservation policy. */
final readonly class ReservationEvaluator
{
    public function __construct(private ReservedSlugPolicyInterface $policy) {}

    public function isReserved(SlugScope $scope, Slug $slug): bool
    {
        return $this->policy->isReserved($scope, $slug);
    }
}
