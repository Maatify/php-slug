<?php

declare(strict_types=1);

namespace Maatify\Slug\Query\DTO;

use JsonSerializable;
use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\Registry\DTO\BindingDTO;
use Maatify\Slug\Registry\DTO\RegistryClaimDTO;
use Maatify\Slug\Shared\Validation\DTOAssertions;

final readonly class CurrentSlugDTO implements JsonSerializable
{
    public function __construct(
        public BindingDTO $binding,
        public RegistryClaimDTO $claim,
        public int $revision,
    ) {
        DTOAssertions::nonNegative($revision, 'revision');
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
