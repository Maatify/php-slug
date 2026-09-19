<?php

declare(strict_types=1);

namespace Maatify\Slug\Canonicalization\DTO;

use JsonSerializable;
use Maatify\Slug\Lifecycle\Consumer\Enum\InputFormCanonicalityEnum;
use Maatify\Slug\Canonicalization\ValueObject\Slug;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;

final readonly class LookupCanonicalizationDTO implements JsonSerializable
{
    public function __construct(
        public SlugProfileKey $profileKey,
        public string $decodedSegment,
        public InputFormCanonicalityEnum $canonicality,
        public ?Slug $canonicalSlug,
    ) {
        if ($canonicality === InputFormCanonicalityEnum::INVALID && $canonicalSlug !== null) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('Invalid lookup cannot have a canonical slug.');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'profile_key' => $this->profileKey->value,
            'decoded_segment' => $this->decodedSegment,
            'canonicality' => $this->canonicality->value,
            'canonical_slug' => $this->canonicalSlug?->value,
        ];
    }
}
