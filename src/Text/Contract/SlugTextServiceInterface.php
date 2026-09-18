<?php

declare(strict_types=1);

namespace Maatify\Slug\Text\Contract;

use Maatify\Slug\Text\DTO\CanonicalSlugDTO;
use Maatify\Slug\Text\DTO\GeneratedSlugDTO;
use Maatify\Slug\Text\DTO\LookupCanonicalizationDTO;
use Maatify\Slug\Profile\Value\SlugProfileKey;

interface SlugTextServiceInterface
{
    public function generateFromSource(SlugProfileKey $profile, string $source): GeneratedSlugDTO;

    public function canonicalizeClaim(SlugProfileKey $profile, string $candidate): CanonicalSlugDTO;

    public function canonicalizeLookup(SlugProfileKey $profile, string $decodedSegment): LookupCanonicalizationDTO;
}
