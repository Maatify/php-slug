<?php

declare(strict_types=1);

namespace Maatify\Slug\Command;

use Maatify\Slug\DTO\BindingIdentityDTO;

final readonly class PurgeBindingCommand
{
    public function __construct(
        public BindingIdentityDTO $binding,
        public int $expectedRevision,
    ) {
        CommandAssertions::revision($expectedRevision);
    }
}
