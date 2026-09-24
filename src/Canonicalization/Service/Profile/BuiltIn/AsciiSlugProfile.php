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
use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\Canonicalization\ValueObject\Slug;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Transliterator;

/** Built-in profile that transliterates source text into lowercase ASCII slugs. */
final class AsciiSlugProfile extends AbstractBuiltinSlugProfile
{
    private const TRANSLITERATOR_ID = 'Any-Latin; Latin-ASCII';

    /** Initializes the profile with the stable `ascii-v1` key. */
    public function __construct()
    {
        parent::__construct(new SlugProfileKey('ascii-v1'));
    }

    /** Generates a transliterated, separator-normalized, bounded ASCII slug. */
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

    /** Canonicalizes a claim without transliterating or inventing separators. */
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

    /** Rejects any value that is not already a canonical lowercase ASCII slug. */
    public function assertCanonicalSlug(string $candidate): void
    {
        $this->assertCanonicalRepresentation($candidate, 'canonical slug');
        CanonicalSlugRules::assertAscii($candidate);
    }

    /** Applies NFC and lowercase conversion before enforcing ASCII slug syntax. */
    private function canonicalizeExact(string $value, string $field): string
    {
        $this->assertSafe($value, $field);
        $canonical = $this->lower($this->normalize($value, $field), $field);
        CanonicalSlugRules::assertAscii($canonical, $field);
        return $canonical;
    }

    /** Converts supported source text through the package's fixed ICU transliterator. */
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
