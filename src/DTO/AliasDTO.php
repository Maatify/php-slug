<?php

declare(strict_types=1);

namespace Maatify\Slug\DTO;

use JsonSerializable;

final readonly class AliasDTO implements JsonSerializable
{
    public function __construct(
        public RegistryClaimDTO $claim,
        public bool $resolvableAsAlias,
        public int $bindingRevision,
    ) {
        DTOAssertions::nonNegative($bindingRevision, 'bindingRevision');
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'claim' => $this->claim,
            'resolvable_as_alias' => $this->resolvableAsAlias,
            'binding_revision' => $this->bindingRevision,
        ];
    }
}
