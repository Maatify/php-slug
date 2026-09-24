<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Command;

use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;

/** Requests permanent purge of an eligible binding at an expected revision. */
final readonly class PurgeBindingCommand
{
    /** Validates the optimistic-lock revision used for the purge decision. */
    public function __construct(
        public BindingIdentityDTO $binding,
        public int $expectedRevision,
    ) {
        CommandAssertions::revision($expectedRevision);
    }
}
