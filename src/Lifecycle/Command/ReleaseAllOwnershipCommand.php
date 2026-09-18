<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Command;

use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Registry\DTO\BindingIdentityDTO;

final readonly class ReleaseAllOwnershipCommand
{
    public function __construct(
        public BindingIdentityDTO $binding,
        public int $expectedRevision,
        public AuditContextDTO $audit,
    ) {
        CommandAssertions::revision($expectedRevision);
    }
}
