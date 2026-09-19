<?php

declare(strict_types=1);

namespace Maatify\Slug\Canonicalization\Service;

use Maatify\Slug\Canonicalization\DTO\CanonicalSlugDTO;
use Maatify\Slug\Canonicalization\DTO\GeneratedSlugDTO;
use Maatify\Slug\Canonicalization\DTO\LookupCanonicalizationDTO;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;

interface SlugTextServiceInterface
{
    public function generateFromSource(SlugProfileKey $profile, string $source): GeneratedSlugDTO;

    public function canonicalizeClaim(SlugProfileKey $profile, string $candidate): CanonicalSlugDTO;

    public function canonicalizeLookup(SlugProfileKey $profile, string $decodedSegment): LookupCanonicalizationDTO;
}
