<?php

declare(strict_types=1);

namespace Maatify\Slug\Canonicalization\Service;

use Maatify\Slug\Canonicalization\DTO\CanonicalSlugDTO;
use Maatify\Slug\Canonicalization\DTO\GeneratedSlugDTO;
use Maatify\Slug\Canonicalization\DTO\LookupCanonicalizationDTO;
use Maatify\Slug\Canonicalization\Service\SlugTextServiceInterface;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistryInterface;

/** Delegates profile-selected text operations without owning persistence state. */
final class SlugTextService implements SlugTextServiceInterface
{
    /** Uses the supplied registry to resolve profiles for each operation. */
    public function __construct(private SlugProfileRegistryInterface $profiles) {}

    /** Generates source text through the selected profile. */
    public function generateFromSource(SlugProfileKey $profile, string $source): GeneratedSlugDTO
    {
        return $this->profiles->get($profile)->generateFromSource($source);
    }

    /** Canonicalizes an exact claim candidate through the selected profile. */
    public function canonicalizeClaim(SlugProfileKey $profile, string $candidate): CanonicalSlugDTO
    {
        return $this->profiles->get($profile)->canonicalizeClaim($candidate);
    }

    /** Classifies and canonicalizes a decoded lookup segment through the selected profile. */
    public function canonicalizeLookup(SlugProfileKey $profile, string $decodedSegment): LookupCanonicalizationDTO
    {
        return $this->profiles->get($profile)->canonicalizeLookup($decodedSegment);
    }
}
