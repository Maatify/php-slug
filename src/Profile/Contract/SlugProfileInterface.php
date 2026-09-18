<?php

declare(strict_types=1);

namespace Maatify\Slug\Profile\Contract;

use Maatify\Slug\Text\DTO\CanonicalSlugDTO;
use Maatify\Slug\Text\DTO\GeneratedSlugDTO;
use Maatify\Slug\Text\DTO\LookupCanonicalizationDTO;
use Maatify\Slug\Profile\Value\SlugProfileKey;

interface SlugProfileInterface
{
    public function key(): SlugProfileKey;

    public function generateFromSource(string $source): GeneratedSlugDTO;

    public function canonicalizeClaim(string $candidate): CanonicalSlugDTO;

    public function canonicalizeLookup(string $decodedSegment): LookupCanonicalizationDTO;

    public function assertCanonicalSlug(string $candidate): void;
}
