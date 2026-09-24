<?php

declare(strict_types=1);

namespace Maatify\Slug\Canonicalization\Service\Profile\BuiltIn;

use Maatify\Slug\Canonicalization\Service\CanonicalSlugRules;
use Maatify\Slug\Canonicalization\DTO\CanonicalSlugDTO;
use Maatify\Slug\Canonicalization\DTO\GeneratedSlugDTO;
use Maatify\Slug\Canonicalization\DTO\LookupCanonicalizationDTO;
use Maatify\Slug\Lifecycle\Consumer\Enum\InputFormCanonicalityEnum;
use Maatify\Slug\Canonicalization\Exception\SlugCannotBeGeneratedException;
use Maatify\Slug\Canonicalization\Mapper\LookupCanonicalizationMapper;
use Maatify\Slug\Canonicalization\ValueObject\Slug;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;

/** Built-in profile that preserves Unicode letters while producing canonical slugs. */
final class UnicodeSlugProfile extends AbstractBuiltinSlugProfile
{
    /** Initializes the profile with the stable `unicode-v1` key. */
    public function __construct()
    {
        parent::__construct(new SlugProfileKey('unicode-v1'));
    }

    /** Generates an NFC-normalized, lowercase, separator-normalized Unicode slug. */
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

    /** Canonicalizes a claim without changing the profile's Unicode slug alphabet. */
    public function canonicalizeClaim(string $candidate): CanonicalSlugDTO
    {
        $canonical = $this->canonicalizeExact($candidate, 'candidate');
        return new CanonicalSlugDTO($this->profileKey, $candidate, Slug::fromProfile($this, $canonical));
    }

    /** Classifies lookup input while preserving invalid input as an invalid result. */
    public function canonicalizeLookup(string $decodedSegment): LookupCanonicalizationDTO
    {
        return LookupCanonicalizationMapper::map(
            function () use ($decodedSegment): LookupCanonicalizationDTO {
                $canonical = $this->canonicalizeExact($decodedSegment, 'lookup segment');
                return new LookupCanonicalizationDTO(
                    $this->profileKey,
                    $decodedSegment,
                    $canonical === $decodedSegment ? InputFormCanonicalityEnum::CANONICAL : InputFormCanonicalityEnum::NON_CANONICAL,
                    Slug::fromProfile($this, $canonical),
                );
            },
            fn(): LookupCanonicalizationDTO => new LookupCanonicalizationDTO(
                $this->profileKey,
                $decodedSegment,
                InputFormCanonicalityEnum::INVALID,
                null,
            ),
        );
    }

    /** Rejects any value that is not already a canonical Unicode slug. */
    public function assertCanonicalSlug(string $candidate): void
    {
        $this->assertCanonicalRepresentation($candidate, 'canonical slug');
        CanonicalSlugRules::assertUnicode($candidate);
    }

    /** Applies NFC and lowercase conversion before enforcing Unicode slug syntax. */
    private function canonicalizeExact(string $value, string $field): string
    {
        $this->assertSafe($value, $field);
        $canonical = $this->lower($this->normalize($value, $field), $field);
        CanonicalSlugRules::assertUnicode($canonical, $field);
        return $canonical;
    }
}
