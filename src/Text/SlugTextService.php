<?php

declare(strict_types=1);

namespace Maatify\Slug\Text;

use Maatify\Slug\Text\Contract\SlugTextServiceInterface;
use Maatify\Slug\Text\DTO\CanonicalSlugDTO;
use Maatify\Slug\Text\DTO\GeneratedSlugDTO;
use Maatify\Slug\Text\DTO\LookupCanonicalizationDTO;
use Maatify\Slug\Profile\Value\SlugProfileKey;
use Maatify\Slug\Profile\Contract\SlugProfileRegistryInterface;

final class SlugTextService implements SlugTextServiceInterface
{
    public function __construct(private SlugProfileRegistryInterface $profiles) {}

    public function generateFromSource(SlugProfileKey $profile, string $source): GeneratedSlugDTO
    {
        return $this->profiles->get($profile)->generateFromSource($source);
    }

    public function canonicalizeClaim(SlugProfileKey $profile, string $candidate): CanonicalSlugDTO
    {
        return $this->profiles->get($profile)->canonicalizeClaim($candidate);
    }

    public function canonicalizeLookup(SlugProfileKey $profile, string $decodedSegment): LookupCanonicalizationDTO
    {
        return $this->profiles->get($profile)->canonicalizeLookup($decodedSegment);
    }
}
