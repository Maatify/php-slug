<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Generation;

use Maatify\Slug\Canonicalization\Exception\SlugCannotBeGeneratedException;
use Maatify\Slug\Canonicalization\Exception\SlugRuntimeCompatibilityException;
use Maatify\Slug\Canonicalization\Service\Profile\BuiltIn\AsciiSlugProfile;
use Maatify\Slug\Canonicalization\Service\Profile\BuiltIn\UnicodeSlugProfile;
use PHPUnit\Framework\TestCase;

final class SourceGenerationTest extends TestCase
{
    public function testBlueprintSourceVectors(): void
    {
        $unicode = $this->unicode();
        $ascii = $this->ascii();

        self::assertSame('آيفون-١٧-برو', $unicode->generateFromSource('آيفون ١٧ برو')->slug->value);
        self::assertSame('iphone-١٧', $unicode->generateFromSource('iPhone-١٧')->slug->value);
        self::assertSame('uber-cafe', $ascii->generateFromSource('Über Café')->slug->value);
    }

    public function testEmojiOnlySourceCannotGenerateAClaimableSlug(): void
    {
        $unicode = $this->unicode();
        $ascii = $this->ascii();

        try {
            $unicode->generateFromSource('❤️🔥');
            self::fail('Unicode emoji-only source was accepted.');
        } catch (SlugCannotBeGeneratedException $exception) {
            self::assertInstanceOf(SlugCannotBeGeneratedException::class, $exception);
        }

        $this->expectException(SlugCannotBeGeneratedException::class);
        $ascii->generateFromSource('❤️🔥');
    }

    public function testSourceNormalizationIsNfcAndGenerationIsBoundedBy160CodePoints(): void
    {
        $unicode = $this->unicode();
        $ascii = $this->ascii();
        $nfc = 'café';
        $nfd = "cafe\u{0301}";

        self::assertSame($unicode->generateFromSource($nfc)->slug->value, $unicode->generateFromSource($nfd)->slug->value);
        self::assertSame($ascii->generateFromSource($nfc)->slug->value, $ascii->generateFromSource($nfd)->slug->value);
        self::assertSame(160, mb_strlen($unicode->generateFromSource(str_repeat('آ', 161))->slug->value, 'UTF-8'));
        self::assertSame(160, strlen($ascii->generateFromSource(str_repeat('a', 161))->slug->value));
    }

    public function testCanonicalGenerationIsIdempotent(): void
    {
        $unicode = $this->unicode();
        $ascii = $this->ascii();

        $unicodeValue = $unicode->generateFromSource('آيفون ١٧ برو')->slug->value;
        $asciiValue = $ascii->generateFromSource('Über Café')->slug->value;
        self::assertSame($unicodeValue, $unicode->generateFromSource($unicodeValue)->slug->value);
        self::assertSame($asciiValue, $ascii->generateFromSource($asciiValue)->slug->value);
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
