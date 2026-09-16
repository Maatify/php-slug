<?php

declare(strict_types=1);

namespace Maatify\Slug\Internal\Claim;

use Maatify\Slug\DTO\BindingDTO;
use Maatify\Slug\DTO\RegistryClaimDTO;

/** @internal Result consumed by the lifecycle boundary. */
final readonly class OwnershipClaimResult
{
    public function __construct(
        public BindingDTO $binding,
        public RegistryClaimDTO $claim,
        public int $attempts,
    ) {}
}
