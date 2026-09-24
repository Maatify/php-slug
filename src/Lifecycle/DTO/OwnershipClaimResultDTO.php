<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\DTO;

/** @internal Immutable internal result for one ownership claim attempt. */
final readonly class OwnershipClaimResultDTO
{
    /** Stores the claim outcome and the registry record selected by the coordinator. */
    public function __construct(
        public BindingDTO $binding,
        public RegistryClaimDTO $claim,
        public int $attempts,
    ) {}
}
