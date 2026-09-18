<?php

declare(strict_types=1);

namespace Maatify\Slug\Registry\Internal\Claim;

use Maatify\Slug\Registry\DTO\BindingDTO;
use Maatify\Slug\Registry\DTO\RegistryClaimDTO;

/** @internal Result consumed by the lifecycle boundary. */
final readonly class OwnershipClaimResult
{
    public function __construct(
        public BindingDTO $binding,
        public RegistryClaimDTO $claim,
        public int $attempts,
    ) {}
}
