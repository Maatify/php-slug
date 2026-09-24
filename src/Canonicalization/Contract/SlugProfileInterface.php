<?php

declare(strict_types=1);

namespace Maatify\Slug\Canonicalization\Contract;

use Maatify\Slug\Canonicalization\DTO\CanonicalSlugDTO;
use Maatify\Slug\Canonicalization\DTO\GeneratedSlugDTO;
use Maatify\Slug\Canonicalization\DTO\LookupCanonicalizationDTO;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;

/**
 * Defines the profile-specific contract for generating and validating slugs.
 *
 * A profile owns the canonical representation rules for source generation,
 * exact claims, and lookup input. Implementations must reject values that are
 * not valid for their profile rather than widening the representation.
 */
interface SlugProfileInterface
{
    /** Returns the stable versioned key used to select this profile. */
    public function key(): SlugProfileKey;

    /** Generates a bounded canonical slug from source text. */
    public function generateFromSource(string $source): GeneratedSlugDTO;

    /** Canonicalizes an exact claim candidate and returns both input forms. */
    public function canonicalizeClaim(string $candidate): CanonicalSlugDTO;

    /** Classifies lookup input as canonical, non-canonical, or invalid. */
    public function canonicalizeLookup(string $decodedSegment): LookupCanonicalizationDTO;

    /** Rejects a value unless it is already canonical for this profile. */
    public function assertCanonicalSlug(string $candidate): void;
}
