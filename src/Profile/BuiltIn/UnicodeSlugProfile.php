<?php

declare(strict_types=1);

namespace Maatify\Slug\Profile\BuiltIn;

use Maatify\Slug\Text\Canonicalization\CanonicalSlugRules;
use Maatify\Slug\Text\DTO\CanonicalSlugDTO;
use Maatify\Slug\Text\DTO\GeneratedSlugDTO;
use Maatify\Slug\Text\DTO\LookupCanonicalizationDTO;
use Maatify\Slug\Query\Enum\InputFormCanonicalityEnum;
use Maatify\Slug\Exception\SlugCannotBeGeneratedException;
use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\Text\Value\Slug;
use Maatify\Slug\Profile\Value\SlugProfileKey;

final class UnicodeSlugProfile extends AbstractBuiltinSlugProfile
{
    public function __construct()
    {
        parent::__construct(new SlugProfileKey('unicode-v1'));
    }

    public function generateFromSource(string $source): GeneratedSlugDTO
    {
        $this->assertSafe($source, 'source');
        $value = $this->lower($this->normalize($source, 'source'), 'source');
        $value = preg_replace('/[^\p{L}\p{M}\p{Nd}-]+/u', '-', $value);
        if ($value === null) {
            throw new SlugCannotBeGeneratedException('Unicode source generation failed.');
        }
        $value = rtrim(preg_replace('/-+/u', '-', trim($value, '-')) ?? '', '-');
        $value = $this->truncateGenerated($value);
        if ($value === '' || ! CanonicalSlugRules::isUnicode($value)) {
            throw new SlugCannotBeGeneratedException('Source did not produce a canonical unicode slug.');
        }

        return new GeneratedSlugDTO($this->profileKey, $source, Slug::fromProfile($this, $value));
    }

    public function canonicalizeClaim(string $candidate): CanonicalSlugDTO
    {
        $canonical = $this->canonicalizeExact($candidate, 'candidate');
        return new CanonicalSlugDTO($this->profileKey, $candidate, Slug::fromProfile($this, $canonical));
    }

    public function canonicalizeLookup(string $decodedSegment): LookupCanonicalizationDTO
    {
        try {
            $canonical = $this->canonicalizeExact($decodedSegment, 'lookup segment');
        } catch (SlugInvalidArgumentException) {
            return new LookupCanonicalizationDTO($this->profileKey, $decodedSegment, InputFormCanonicalityEnum::INVALID, null);
        }

        return new LookupCanonicalizationDTO(
            $this->profileKey,
            $decodedSegment,
            $canonical === $decodedSegment ? InputFormCanonicalityEnum::CANONICAL : InputFormCanonicalityEnum::NON_CANONICAL,
            Slug::fromProfile($this, $canonical),
        );
    }

    public function assertCanonicalSlug(string $candidate): void
    {
        $this->assertCanonicalRepresentation($candidate, 'canonical slug');
        CanonicalSlugRules::assertUnicode($candidate);
    }

    private function canonicalizeExact(string $value, string $field): string
    {
        $this->assertSafe($value, $field);
        $canonical = $this->lower($this->normalize($value, $field), $field);
        CanonicalSlugRules::assertUnicode($canonical, $field);
        return $canonical;
    }
}
