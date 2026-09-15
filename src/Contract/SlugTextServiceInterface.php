<?php

declare(strict_types=1);

namespace Maatify\Slug\Contract;

use Maatify\Slug\DTO\CanonicalSlugDTO;
use Maatify\Slug\DTO\GeneratedSlugDTO;
use Maatify\Slug\DTO\LookupCanonicalizationDTO;
use Maatify\Slug\Identity\SlugProfileKey;

interface SlugTextServiceInterface
{
    public function generateFromSource(SlugProfileKey $profile, string $source): GeneratedSlugDTO;

    public function canonicalizeClaim(SlugProfileKey $profile, string $candidate): CanonicalSlugDTO;

    public function canonicalizeLookup(SlugProfileKey $profile, string $decodedSegment): LookupCanonicalizationDTO;
}
