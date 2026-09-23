<?php

declare(strict_types=1);

namespace Maatify\Slug\Canonicalization\DTO;

use JsonSerializable;
use Maatify\Slug\Canonicalization\ValueObject\Slug;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;

/** Immutable result of generating a slug from source text. */
final readonly class GeneratedSlugDTO implements JsonSerializable
{
    /** Stores generated output together with its source and profile metadata. */
    public function __construct(
        public SlugProfileKey $profileKey,
        public string $source,
        public Slug $slug,
    ) {}

    /**
     * Returns the profile key, original source, and generated slug value.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'profile_key' => $this->profileKey->value,
            'source' => $this->source,
            'slug' => $this->slug->value,
        ];
    }
}
