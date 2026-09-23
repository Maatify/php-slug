<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Profile;

use LogicException;
use Maatify\Slug\Canonicalization\DTO\CanonicalSlugDTO;
use Maatify\Slug\Canonicalization\DTO\GeneratedSlugDTO;
use Maatify\Slug\Canonicalization\DTO\LookupCanonicalizationDTO;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Canonicalization\Contract\SlugProfileInterface;

/** Stub profile for tests that must prove an operation does not invoke profile conversion. */
final class StubSlugProfile implements SlugProfileInterface
{
    /** Retains the supplied key while all conversion operations remain intentionally unused. */
    public function __construct(private SlugProfileKey $profileKey) {}

    /** Returns the configured key without performing conversion. */
    public function key(): SlugProfileKey
    {
        return $this->profileKey;
    }

    /** Fails if generation is unexpectedly invoked by the test subject. */
    public function generateFromSource(string $source): GeneratedSlugDTO
    {
        throw new LogicException('Stub profile generation is not used.');
    }

    /** Fails if claim canonicalization is unexpectedly invoked by the test subject. */
    public function canonicalizeClaim(string $candidate): CanonicalSlugDTO
    {
        throw new LogicException('Stub profile claim canonicalization is not used.');
    }

    /** Fails if lookup canonicalization is unexpectedly invoked by the test subject. */
    public function canonicalizeLookup(string $decodedSegment): LookupCanonicalizationDTO
    {
        throw new LogicException('Stub profile lookup canonicalization is not used.');
    }

    /** Intentionally performs no validation because this stub is used only for non-conversion paths. */
    public function assertCanonicalSlug(string $candidate): void {}
}
