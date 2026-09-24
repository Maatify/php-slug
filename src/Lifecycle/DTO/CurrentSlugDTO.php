<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\DTO;

use JsonSerializable;
use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\Lifecycle\DTO\BindingDTO;
use Maatify\Slug\Lifecycle\DTO\RegistryClaimDTO;

/** Immutable read model pairing a binding with its current registry claim. */
final readonly class CurrentSlugDTO implements JsonSerializable
{
    /** Enforces that the returned claim matches the binding's current slug and revision. */
    public function __construct(
        public BindingDTO $binding,
        public RegistryClaimDTO $claim,
        public int $revision,
    ) {
        if ($revision < 0) {
            throw new SlugInvalidArgumentException('revision must be non-negative.');
        }
        if ($claim->slug->value !== $binding->state->currentSlug?->value) {
            throw new SlugInvalidArgumentException('Current slug claim must match binding current slug.');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'binding' => $this->binding,
            'claim' => $this->claim,
            'revision' => $this->revision,
        ];
    }
}
