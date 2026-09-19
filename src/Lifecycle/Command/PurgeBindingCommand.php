<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Command;

use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;

final readonly class PurgeBindingCommand
{
    public function __construct(
        public BindingIdentityDTO $binding,
        public int $expectedRevision,
    ) {
        CommandAssertions::revision($expectedRevision);
    }
}
