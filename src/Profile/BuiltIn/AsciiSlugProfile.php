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
use Transliterator;

final class AsciiSlugProfile extends AbstractBuiltinSlugProfile
{
    private const TRANSLITERATOR_ID = 'Any-Latin; Latin-ASCII';

    public function __construct()
    {
        parent::__construct(new SlugProfileKey('ascii-v1'));
    }

    public function generateFromSource(string $source): GeneratedSlugDTO
    {
        $this->assertSafe($source, 'source');
        $value = $this->transliterate($this->normalize($source, 'source'), 'source');
        $value = $this->lower($value, 'source');
        $value = preg_replace('/[^a-z0-9-]+/', '-', $value);
        if ($value === null) {
            throw new SlugCannotBeGeneratedException('ASCII source generation failed.');
        }
        $value = rtrim(preg_replace('/-+/', '-', trim($value, '-')) ?? '', '-');
        $value = $this->truncateGenerated($value);
        if ($value === '' || ! CanonicalSlugRules::isAscii($value)) {
            throw new SlugCannotBeGeneratedException('Source did not produce a canonical ASCII slug.');
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
        CanonicalSlugRules::assertAscii($candidate);
    }

    private function canonicalizeExact(string $value, string $field): string
    {
        $this->assertSafe($value, $field);
        $canonical = $this->lower($this->normalize($value, $field), $field);
        CanonicalSlugRules::assertAscii($canonical, $field);
        return $canonical;
    }

    private function transliterate(string $value, string $field): string
    {
        $transliterator = Transliterator::create(self::TRANSLITERATOR_ID);
        if ($transliterator === null) {
            throw new SlugInvalidArgumentException(sprintf('%s ICU transliteration failed.', $field));
        }
        $transliterated = $transliterator->transliterate($value);
        if ($transliterated === false) {
            throw new SlugInvalidArgumentException(sprintf('%s ICU transliteration failed.', $field));
        }
        return $transliterated;
    }
}
