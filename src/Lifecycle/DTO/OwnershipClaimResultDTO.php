<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\DTO;

/** @internal Result consumed by the lifecycle boundary. */
final readonly class OwnershipClaimResultDTO
{
    public function __construct(
        public BindingDTO $binding,
        public RegistryClaimDTO $claim,
        public int $attempts,
    ) {}
}
