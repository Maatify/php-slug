<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Validation;

use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\Lifecycle\Service\Allocation\SuffixCandidateGenerator;
use Maatify\Slug\Canonicalization\Service\Profile\BuiltIn\AsciiSlugProfile;
use Maatify\Slug\Canonicalization\Service\Profile\BuiltIn\UnicodeSlugProfile;
use PHPUnit\Framework\TestCase;

final class ProfileInputValidationTest extends TestCase
{
    /** @return iterable<string, array{0: string}> */
    public static function forbiddenInputs(): iterable
    {
        yield 'invalid utf8' => ["\xC3\x28"];
        yield 'nul' => ["hello\0world"];
        yield 'control' => ["hello\x01world"];
        yield 'format' => ["hello\u{200D}world"];
        yield 'path slash' => ['hello/world'];
        yield 'path backslash' => ['hello\\world'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('forbiddenInputs')]
    public function testForbiddenInputIsRejectedBeforeGeneration(string $input): void
    {
        $unicode = $this->unicode();
        $ascii = $this->ascii();

        try {
            $unicode->generateFromSource($input);
            self::fail('Unicode profile accepted forbidden input.');
        } catch (SlugInvalidArgumentException $exception) {
            self::assertInstanceOf(SlugInvalidArgumentException::class, $exception);
        }

        $this->expectException(SlugInvalidArgumentException::class);
        $ascii->canonicalizeClaim($input);
    }

    public function testExactClaimRejects161CodePointsWithoutTruncation(): void
    {
        $unicode = $this->unicode();
        $ascii = $this->ascii();

        try {
            $unicode->canonicalizeClaim(str_repeat('آ', 161));
            self::fail('Unicode overlong exact claim was accepted.');
        } catch (SlugInvalidArgumentException $exception) {
            self::assertInstanceOf(SlugInvalidArgumentException::class, $exception);
        }

        $this->expectException(SlugInvalidArgumentException::class);
        $ascii->canonicalizeClaim(str_repeat('a', 161));
    }

    public function testSuffixPreparationKeepsEveryBoundedCandidateWithin160CodePoints(): void
    {
        $candidates = SuffixCandidateGenerator::prepare(str_repeat('آ', 160));

        self::assertCount(1000, $candidates);
        self::assertSame(str_repeat('آ', 160), $candidates[0]);
        self::assertSame(str_repeat('آ', 158) . '-2', $candidates[1]);
        self::assertSame(str_repeat('آ', 155) . '-1000', $candidates[999]);
        foreach ($candidates as $candidate) {
            self::assertLessThanOrEqual(160, mb_strlen($candidate, 'UTF-8'));
        }
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
