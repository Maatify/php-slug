<?php

declare(strict_types=1);

namespace Maatify\Slug\Canonicalization\DTO;

use JsonSerializable;
use Maatify\Slug\Canonicalization\ValueObject\Slug;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;

final readonly class CanonicalSlugDTO implements JsonSerializable
{
    public function __construct(
        public SlugProfileKey $profileKey,
        public string $input,
        public Slug $slug,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'profile_key' => $this->profileKey->value,
            'input' => $this->input,
            'slug' => $this->slug->value,
        ];
    }
}
