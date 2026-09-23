<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\DTO;

use JsonSerializable;

/** Immutable read model for an alias claim and its resolution state. */
final readonly class AliasDTO implements JsonSerializable
{
    /** Enforces a non-negative binding revision for the alias snapshot. */
    public function __construct(
        public RegistryClaimDTO $claim,
        public bool $resolvableAsAlias,
        public int $bindingRevision,
    ) {
        if ($bindingRevision < 0) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('bindingRevision must be non-negative.');
        }
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
