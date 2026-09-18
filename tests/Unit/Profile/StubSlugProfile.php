<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Profile;

use LogicException;
use Maatify\Slug\Text\DTO\CanonicalSlugDTO;
use Maatify\Slug\Text\DTO\GeneratedSlugDTO;
use Maatify\Slug\Text\DTO\LookupCanonicalizationDTO;
use Maatify\Slug\Profile\Value\SlugProfileKey;
use Maatify\Slug\Profile\Contract\SlugProfileInterface;

final class StubSlugProfile implements SlugProfileInterface
{
    public function __construct(private SlugProfileKey $profileKey) {}

    public function key(): SlugProfileKey
    {
        return $this->profileKey;
    }

    public function generateFromSource(string $source): GeneratedSlugDTO
    {
        throw new LogicException('Stub profile generation is not used.');
    }

    public function canonicalizeClaim(string $candidate): CanonicalSlugDTO
    {
        throw new LogicException('Stub profile claim canonicalization is not used.');
    }

    public function canonicalizeLookup(string $decodedSegment): LookupCanonicalizationDTO
    {
        throw new LogicException('Stub profile lookup canonicalization is not used.');
    }

    public function assertCanonicalSlug(string $candidate): void {}
}
