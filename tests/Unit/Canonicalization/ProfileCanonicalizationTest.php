<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Canonicalization;

use Maatify\Slug\Lifecycle\Consumer\Enum\InputFormCanonicalityEnum;
use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\Canonicalization\ValueObject\Slug;
use Maatify\Slug\Canonicalization\Service\Profile\BuiltIn\AsciiSlugProfile;
use Maatify\Slug\Canonicalization\Service\Profile\BuiltIn\UnicodeSlugProfile;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testUnicodeCanonicalGateAcceptsOnlyAlreadyCanonicalRepresentation(): void
    {
        $profile = $this->unicode();

        self::assertSame('iphone-pro', Slug::fromProfile($profile, 'iphone-pro')->value);
        self::assertSame('café', Slug::fromProfile($profile, 'café')->value);

        foreach (['HELLO', 'iPhone-Pro', "cafe\u{0301}"] as $candidate) {
            try {
                Slug::fromProfile($profile, $candidate);
                self::fail(sprintf('Non-canonical representation was accepted: %s', $candidate));
            } catch (SlugInvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        self::assertSame('iphone-pro', $profile->canonicalizeClaim('iPhone-Pro')->slug->value);
        self::assertSame('café', $profile->canonicalizeClaim("cafe\u{0301}")->slug->value);
    }

    public function testAsciiCanonicalGateDoesNotWidenRepresentation(): void
    {
        $profile = $this->ascii();

        self::assertSame('hello-world', Slug::fromProfile($profile, 'hello-world')->value);
        self::assertSame('hello-world', $profile->canonicalizeClaim('HELLO-WORLD')->slug->value);

        foreach (['HELLO', 'Hello-World', 'café'] as $candidate) {
            try {
                Slug::fromProfile($profile, $candidate);
                self::fail(sprintf('Non-canonical ASCII representation was accepted: %s', $candidate));
            } catch (SlugInvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
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

    /**
     * @return iterable<string, array{0: 'unicode'|'ascii', 1: string, 2: string}>
     */
    public static function canonicalCorpus(): iterable
    {
        yield 'unicode arabic' => ['unicode', 'آيفون ١٧ برو', 'آيفون-١٧-برو'];
        yield 'unicode nfc' => ['unicode', 'café', 'café'];
        yield 'unicode cyrillic' => ['unicode', 'Пример Теста', 'пример-теста'];
        yield 'ascii transliterated source' => ['ascii', 'Über Café', 'uber-cafe'];
        yield 'ascii punctuation source' => ['ascii', 'Hello, World!', 'hello-world'];
        yield 'ascii digits' => ['ascii', 'Version 17', 'version-17'];
    }

    #[DataProvider('canonicalCorpus')]
    public function testCanonicalCorpusIsIdempotentAcrossSourceClaimAndLookup(
        string $profileName,
        string $source,
        string $expectedCanonical,
    ): void {
        $profile = $profileName === 'unicode' ? $this->unicode() : $this->ascii();

        $generated = $profile->generateFromSource($source)->slug->value;
        self::assertSame($expectedCanonical, $generated);
        self::assertSame($generated, $profile->generateFromSource($generated)->slug->value);
        self::assertSame($generated, $profile->canonicalizeClaim($generated)->slug->value);

        $lookup = $profile->canonicalizeLookup($generated);
        self::assertSame(InputFormCanonicalityEnum::CANONICAL, $lookup->canonicality);
        self::assertSame($generated, $lookup->canonicalSlug?->value);
    }

    public function testUnicodeCanonicalizationTreatsNfcAndNfdAsEquivalentBeforeTheGate(): void
    {
        $profile = $this->unicode();
        $nfc = $profile->canonicalizeClaim('café')->slug->value;
        $nfd = $profile->canonicalizeClaim("cafe\u{0301}")->slug->value;

        self::assertSame('café', $nfc);
        self::assertSame($nfc, $nfd);
        self::assertSame(InputFormCanonicalityEnum::NON_CANONICAL, $profile->canonicalizeLookup("cafe\u{0301}")->canonicality);
        self::assertSame($nfc, $profile->canonicalizeLookup("cafe\u{0301}")->canonicalSlug?->value);
    }

    private function unicode(): UnicodeSlugProfile
    {
        return new UnicodeSlugProfile();
    }

    private function ascii(): AsciiSlugProfile
    {
        return new AsciiSlugProfile();
    }
}
