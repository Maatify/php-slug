<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Canonicalization;

use Maatify\Slug\Enum\InputFormCanonicalityEnum;
use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\Exception\SlugRuntimeCompatibilityException;
use Maatify\Slug\Profile\BuiltIn\AsciiSlugProfile;
use Maatify\Slug\Profile\BuiltIn\UnicodeSlugProfile;
use PHPUnit\Framework\TestCase;

final class ProfileCanonicalizationTest extends TestCase
{
    public function testUnicodeExactClaimUsesOnlyNfcAndLowercase(): void
    {
        $profile = $this->unicode();

        self::assertSame('hello', $profile->canonicalizeClaim('HELLO')->slug->value);
        self::assertSame('iphone-pro', $profile->canonicalizeClaim('iPhone-Pro')->slug->value);

        $this->expectException(SlugInvalidArgumentException::class);
        $profile->canonicalizeClaim('Hello World');
    }

    public function testAsciiExactClaimDoesNotTransliterateOrCreateSeparators(): void
    {
        $profile = $this->ascii();

        self::assertSame('hello', $profile->canonicalizeClaim('HELLO')->slug->value);

        $this->expectException(SlugInvalidArgumentException::class);
        $profile->canonicalizeClaim('Über Café');
    }

    public function testLookupIsNonLossyAndPreservesInvalidity(): void
    {
        $unicode = $this->unicode();
        $nonCanonical = $unicode->canonicalizeLookup('iPhone-Pro');
        self::assertSame(InputFormCanonicalityEnum::NON_CANONICAL, $nonCanonical->canonicality);
        self::assertSame('iphone-pro', $nonCanonical->canonicalSlug?->value);

        $invalid = $unicode->canonicalizeLookup('hello!!!');
        self::assertSame(InputFormCanonicalityEnum::INVALID, $invalid->canonicality);
        self::assertNull($invalid->canonicalSlug);
    }

    public function testLookupAndClaimAreIdempotentForCanonicalForms(): void
    {
        $unicode = $this->unicode();
        $ascii = $this->ascii();

        self::assertSame(InputFormCanonicalityEnum::CANONICAL, $unicode->canonicalizeLookup('iphone-pro')->canonicality);
        self::assertSame('iphone-pro', $unicode->canonicalizeClaim('iphone-pro')->slug->value);
        self::assertSame(InputFormCanonicalityEnum::CANONICAL, $ascii->canonicalizeLookup('uber-cafe')->canonicality);
        self::assertSame('uber-cafe', $ascii->canonicalizeClaim('uber-cafe')->slug->value);
    }

    private function unicode(): UnicodeSlugProfile
    {
        try {
            return new UnicodeSlugProfile();
        } catch (SlugRuntimeCompatibilityException $exception) {
            self::markTestSkipped($exception->getMessage());
        }
    }

    private function ascii(): AsciiSlugProfile
    {
        try {
            return new AsciiSlugProfile();
        } catch (SlugRuntimeCompatibilityException $exception) {
            self::markTestSkipped($exception->getMessage());
        }
    }
}
