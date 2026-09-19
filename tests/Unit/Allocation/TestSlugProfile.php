<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Allocation;

use Maatify\Slug\Canonicalization\Service\CanonicalSlugRules;
use Maatify\Slug\Canonicalization\DTO\CanonicalSlugDTO;
use Maatify\Slug\Canonicalization\DTO\GeneratedSlugDTO;
use Maatify\Slug\Canonicalization\DTO\LookupCanonicalizationDTO;
use Maatify\Slug\Lifecycle\Consumer\Enum\InputFormCanonicalityEnum;
use Maatify\Slug\Canonicalization\Exception\SlugCannotBeGeneratedException;
use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\Canonicalization\ValueObject\Slug;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Canonicalization\Contract\SlugProfileInterface;

final class TestSlugProfile implements SlugProfileInterface
{
    public function __construct(private SlugProfileKey $profileKey = new SlugProfileKey('ascii-v1')) {}

    public function key(): SlugProfileKey
    {
        return $this->profileKey;
    }

    public function generateFromSource(string $source): GeneratedSlugDTO
    {
        $value = preg_replace('/[^a-z0-9]+/i', '-', strtolower($source));
        if ($value === null) {
            throw new SlugCannotBeGeneratedException('Test source generation failed.');
        }
        $value = trim($value, '-');
        if ($value === '') {
            throw new SlugCannotBeGeneratedException('Test source did not produce a slug.');
        }
        $value = mb_substr($value, 0, CanonicalSlugRules::MAX_CODE_POINTS, 'UTF-8');

        return new GeneratedSlugDTO($this->profileKey, $source, Slug::fromProfile($this, $value));
    }

    public function canonicalizeClaim(string $candidate): CanonicalSlugDTO
    {
        $value = strtolower($candidate);
        CanonicalSlugRules::assertAscii($value, 'candidate');

        return new CanonicalSlugDTO($this->profileKey, $candidate, Slug::fromProfile($this, $value));
    }

    public function canonicalizeLookup(string $decodedSegment): LookupCanonicalizationDTO
    {
        try {
            $canonical = $this->canonicalizeClaim($decodedSegment)->slug;
        } catch (SlugInvalidArgumentException) {
            return new LookupCanonicalizationDTO($this->profileKey, $decodedSegment, InputFormCanonicalityEnum::INVALID, null);
        }

        return new LookupCanonicalizationDTO(
            $this->profileKey,
            $decodedSegment,
            $canonical->value === $decodedSegment
                ? InputFormCanonicalityEnum::CANONICAL
                : InputFormCanonicalityEnum::NON_CANONICAL,
            $canonical,
        );
    }

    public function assertCanonicalSlug(string $candidate): void
    {
        CanonicalSlugRules::assertAscii($candidate);
    }
}
