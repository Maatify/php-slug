<?php

declare(strict_types=1);

namespace Maatify\Slug\Canonicalization\DTO;

use JsonSerializable;
use Maatify\Slug\Canonicalization\ValueObject\Slug;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;

/** Immutable result of canonicalizing a value for an exact claim. */
final readonly class CanonicalSlugDTO implements JsonSerializable
{
    /** Stores the canonical slug and the profile that produced it. */
    public function __construct(
        public SlugProfileKey $profileKey,
        public string $input,
        public Slug $slug,
    ) {}

    /**
     * Returns the profile key, original input, and canonical slug value.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'profile_key' => $this->profileKey->value,
            'input' => $this->input,
            'slug' => $this->slug->value,
        ];
    }
}
