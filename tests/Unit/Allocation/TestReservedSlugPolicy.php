<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Allocation;

use Maatify\Slug\Lifecycle\Contract\ReservedSlugPolicyInterface;
use Maatify\Slug\Text\Value\Slug;
use Maatify\Slug\Scope\Value\SlugScope;

final class TestReservedSlugPolicy implements ReservedSlugPolicyInterface
{
    /** @var array<string, true> */
    private array $reserved;

    /** @param list<string> $reserved */
    public function __construct(array $reserved = [])
    {
        $this->reserved = array_fill_keys($reserved, true);
    }

    public function isReserved(SlugScope $scope, Slug $slug): bool
    {
        return isset($this->reserved[$scope->namespace . ':' . $slug->value])
            || isset($this->reserved[$slug->value]);
    }
}
