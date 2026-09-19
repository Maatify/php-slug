<?php

declare(strict_types=1);

namespace Maatify\Slug\Canonicalization\Contract;

use Maatify\Slug\Canonicalization\DTO\CanonicalSlugDTO;
use Maatify\Slug\Canonicalization\DTO\GeneratedSlugDTO;
use Maatify\Slug\Canonicalization\DTO\LookupCanonicalizationDTO;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;

interface SlugProfileInterface
{
    public function key(): SlugProfileKey;

    public function generateFromSource(string $source): GeneratedSlugDTO;

    public function canonicalizeClaim(string $candidate): CanonicalSlugDTO;

    public function canonicalizeLookup(string $decodedSegment): LookupCanonicalizationDTO;

    public function assertCanonicalSlug(string $candidate): void;
}
