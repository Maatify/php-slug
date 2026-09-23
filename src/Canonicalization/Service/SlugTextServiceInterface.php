<?php

declare(strict_types=1);

namespace Maatify\Slug\Canonicalization\Service;

use Maatify\Slug\Canonicalization\DTO\CanonicalSlugDTO;
use Maatify\Slug\Canonicalization\DTO\GeneratedSlugDTO;
use Maatify\Slug\Canonicalization\DTO\LookupCanonicalizationDTO;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;

/** Public stateless boundary for profile-selected slug text operations. */
interface SlugTextServiceInterface
{
    /** Generates a profile-specific slug from source text. */
    public function generateFromSource(SlugProfileKey $profile, string $source): GeneratedSlugDTO;

    /** Canonicalizes a candidate for an exact claim. */
    public function canonicalizeClaim(SlugProfileKey $profile, string $candidate): CanonicalSlugDTO;

    /** Classifies a decoded lookup segment and returns its canonical form when valid. */
    public function canonicalizeLookup(SlugProfileKey $profile, string $decodedSegment): LookupCanonicalizationDTO;
}
