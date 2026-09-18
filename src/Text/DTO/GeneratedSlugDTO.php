<?php

declare(strict_types=1);

namespace Maatify\Slug\Text\DTO;

use JsonSerializable;
use Maatify\Slug\Text\Value\Slug;
use Maatify\Slug\Profile\Value\SlugProfileKey;

final readonly class GeneratedSlugDTO implements JsonSerializable
{
    public function __construct(
        public SlugProfileKey $profileKey,
        public string $source,
        public Slug $slug,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'profile_key' => $this->profileKey->value,
            'source' => $this->source,
            'slug' => $this->slug->value,
        ];
    }
}
