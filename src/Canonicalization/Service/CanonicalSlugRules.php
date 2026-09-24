<?php

declare(strict_types=1);

namespace Maatify\Slug\Canonicalization\Service;

use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\Canonicalization\Service\SlugInputValidator;

/** Validates the bounded syntax shared by the built-in slug profiles. */
final class CanonicalSlugRules
{
    public const MAX_CODE_POINTS = 160;

    /** Requires a Unicode slug made of letters, marks, digits, and single hyphen separators. */
    public static function assertUnicode(string $value, string $field = 'slug'): void
    {
        SlugInputValidator::assertSafe($value, $field);
        SlugInputValidator::assertCodePointLength($value, self::MAX_CODE_POINTS, $field);
        if (preg_match('/\A[\p{L}\p{Nd}][\p{L}\p{M}\p{Nd}]*(?:-[\p{L}\p{Nd}][\p{L}\p{M}\p{Nd}]*)*\z/u', $value) !== 1) {
            throw new SlugInvalidArgumentException(sprintf('%s is not a canonical unicode slug.', $field));
        }
    }

    /** Requires a lowercase ASCII slug made of letters, digits, and single hyphen separators. */
    public static function assertAscii(string $value, string $field = 'slug'): void
    {
        SlugInputValidator::assertSafe($value, $field);
        SlugInputValidator::assertCodePointLength($value, self::MAX_CODE_POINTS, $field);
        if (preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $value) !== 1) {
            throw new SlugInvalidArgumentException(sprintf('%s is not a canonical ASCII slug.', $field));
        }
    }

    /** Returns whether a value satisfies the Unicode syntax and code-point limit. */
    public static function isUnicode(string $value): bool
    {
        return preg_match('/\A[\p{L}\p{Nd}][\p{L}\p{M}\p{Nd}]*(?:-[\p{L}\p{Nd}][\p{L}\p{M}\p{Nd}]*)*\z/u', $value) === 1
            && SlugInputValidator::codePointLength($value) <= self::MAX_CODE_POINTS;
    }

    /** Returns whether a value satisfies the lowercase ASCII syntax and length limit. */
    public static function isAscii(string $value): bool
    {
        return preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $value) === 1
            && strlen($value) <= self::MAX_CODE_POINTS;
    }

    private function __construct() {}
}
