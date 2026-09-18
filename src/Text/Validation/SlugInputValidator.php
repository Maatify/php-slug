<?php

declare(strict_types=1);

namespace Maatify\Slug\Text\Validation;

use Maatify\Slug\Exception\SlugInvalidArgumentException;

final class SlugInputValidator
{
    public static function assertSafe(string $value, string $field = 'slug input'): void
    {
        if (preg_match('//u', $value) !== 1) {
            throw new SlugInvalidArgumentException(sprintf('%s must be valid UTF-8.', $field));
        }

        if (preg_match('/[\x00\p{Cc}\p{Cs}\p{Cf}]/u', $value) === 1) {
            throw new SlugInvalidArgumentException(sprintf('%s contains a forbidden control or format character.', $field));
        }

        if (strpbrk($value, '/\\') !== false) {
            throw new SlugInvalidArgumentException(sprintf('%s contains a forbidden path separator.', $field));
        }
    }

    public static function assertCodePointLength(string $value, int $maxCodePoints, string $field): void
    {
        if (mb_strlen($value, 'UTF-8') > $maxCodePoints) {
            throw new SlugInvalidArgumentException(sprintf('%s exceeds %d Unicode code points.', $field, $maxCodePoints));
        }
    }

    public static function codePointLength(string $value): int
    {
        return mb_strlen($value, 'UTF-8');
    }

    private function __construct() {}
}
