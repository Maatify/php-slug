<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Allocation;

use Maatify\Slug\Lifecycle\Contract\ReservedSlugPolicyInterface;
use Maatify\Slug\Canonicalization\ValueObject\Slug;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;

/** Test policy supporting global slugs and namespace-specific `namespace:slug` reservations. */
final class TestReservedSlugPolicy implements ReservedSlugPolicyInterface
{
    /** @var array<string, true> */
    private array $reserved;

    /** @param list<string> $reserved Global slug values or namespace-qualified `namespace:slug` values. */
    public function __construct(array $reserved = [])
    {
        $this->reserved = array_fill_keys($reserved, true);
    }

    /** Checks the namespace-qualified reservation first, then the global slug reservation. */
    public function isReserved(SlugScope $scope, Slug $slug): bool
    {
        return isset($this->reserved[$scope->namespace . ':' . $slug->value])
            || isset($this->reserved[$slug->value]);
    }
}
