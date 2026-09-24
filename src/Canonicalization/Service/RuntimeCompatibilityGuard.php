<?php

declare(strict_types=1);

namespace Maatify\Slug\Canonicalization\Service;

use IntlChar;
use Maatify\Slug\Canonicalization\Exception\SlugRuntimeCompatibilityException;
use Normalizer;
use Transliterator;

/** Fails closed unless the ICU and Unicode tuple required by built-in profiles is available. */
final class RuntimeCompatibilityGuard
{
    /** Validates the actual ICU runtime and its required normalization capabilities. */
    public static function assertSupported(): void
    {
        if (! defined('INTL_ICU_VERSION')) {
            throw new SlugRuntimeCompatibilityException('ICU runtime version is unavailable.');
        }

        $icuVersion = explode('.', INTL_ICU_VERSION);
        if (! class_exists(IntlChar::class)) {
            throw new SlugRuntimeCompatibilityException('ICU Unicode data version is unavailable.');
        }
        $unicodeVersion = IntlChar::getUnicodeVersion();
        self::assertTupleSupported(
            self::versionPart($icuVersion[0]),
            self::versionPart($unicodeVersion[0]),
            self::versionPart($unicodeVersion[1]),
            class_exists(Normalizer::class),
            class_exists(Transliterator::class),
        );
    }

    /** Validates the supported ICU/Unicode tuple and required ext-intl classes. */
    public static function assertTupleSupported(
        int $icuMajor,
        int $unicodeMajor,
        int $unicodeMinor,
        bool $normalizerAvailable = true,
        bool $transliteratorAvailable = true,
    ): void {
        if (
            $icuMajor !== 74
            || $unicodeMajor !== 15
            || $unicodeMinor !== 1
            || ! $normalizerAvailable
            || ! $transliteratorAvailable
        ) {
            throw new SlugRuntimeCompatibilityException('Built-in profiles require ICU 74 with Unicode data 15.1 and ext-intl normalization/transliteration.');
        }
    }

    private function __construct() {}

    /** Converts a version component into an integer, returning zero for malformed input. */
    private static function versionPart(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }
        return 0;
    }
}
