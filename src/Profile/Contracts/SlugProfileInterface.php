<?php

declare(strict_types=1);

namespace Maatify\Slug\Profile\Contracts;

use Maatify\Slug\DTO\CanonicalSlugDTO;
use Maatify\Slug\DTO\GeneratedSlugDTO;
use Maatify\Slug\DTO\LookupCanonicalizationDTO;
use Maatify\Slug\Identity\SlugProfileKey;

interface SlugProfileInterface
{
    public function key(): SlugProfileKey;

    public function generateFromSource(string $source): GeneratedSlugDTO;

    public function canonicalizeClaim(string $candidate): CanonicalSlugDTO;

    public function canonicalizeLookup(string $decodedSegment): LookupCanonicalizationDTO;

    public function assertCanonicalSlug(string $candidate): void;
}
