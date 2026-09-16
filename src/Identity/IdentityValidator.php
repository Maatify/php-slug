<?php

declare(strict_types=1);

namespace Maatify\Slug\Identity;

use Maatify\Slug\Exception\SlugInvalidArgumentException;

final class IdentityValidator
{
    public static function assertProfileKey(string $value): void
    {
        if (preg_match('/\\A(?=.{1,63}\\z)[a-z][a-z0-9-]*-v[1-9][0-9]*\\z/', $value) !== 1) {
            throw new SlugInvalidArgumentException('Invalid slug profile key.');
        }
    }

    public static function assertNamespace(string $value): void
    {
        if (preg_match('/\\A[a-z][a-z0-9-]{0,62}\\z/', $value) !== 1) {
            throw new SlugInvalidArgumentException('Invalid slug scope namespace.');
        }
    }

    public static function assertDimension(string $value, int $maxCodePoints, string $field): void
    {
        self::assertOpaqueString($value, $maxCodePoints, $field);
    }

    public static function assertValidUtf8(string $value, string $field): void
    {
        if (preg_match('//u', $value) !== 1) {
            throw new SlugInvalidArgumentException(sprintf('%s must be valid UTF-8.', $field));
        }
    }

    public static function assertOpaqueString(string $value, int $maxCodePoints, string $field): void
    {
        if ($value === '' || preg_match('//u', $value) !== 1) {
            throw new SlugInvalidArgumentException(sprintf('%s must be non-empty valid UTF-8.', $field));
        }

        if (preg_match('/[\\p{Cc}\\p{Cs}\\p{Cf}]/u', $value) === 1 || strpbrk($value, '/\\\\') !== false) {
            throw new SlugInvalidArgumentException(sprintf('%s contains a forbidden character.', $field));
        }

        if (preg_match('/^[\\x09-\\x0D\\x20\\x{00A0}]|[\\x09-\\x0D\\x20\\x{00A0}]$/u', $value) === 1) {
            throw new SlugInvalidArgumentException(sprintf('%s has forbidden boundary whitespace.', $field));
        }

        if (mb_strlen($value, 'UTF-8') > $maxCodePoints) {
            throw new SlugInvalidArgumentException(sprintf('%s exceeds its maximum length.', $field));
        }
    }

    public static function assertAuditString(string $value, int $maxCodePoints, string $field, bool $allowPathSeparators = false): void
    {
        if ($value === '' || preg_match('//u', $value) !== 1) {
            throw new SlugInvalidArgumentException(sprintf('%s must be non-empty valid UTF-8.', $field));
        }

        if (preg_match('/[\\p{Cc}\\p{Cs}\\p{Cf}]/u', $value) === 1) {
            throw new SlugInvalidArgumentException(sprintf('%s contains a forbidden character.', $field));
        }

        if (! $allowPathSeparators && strpbrk($value, '/\\\\') !== false) {
            throw new SlugInvalidArgumentException(sprintf('%s contains a forbidden character.', $field));
        }

        if (preg_match('/^[\\x09-\\x0D\\x20\\x{00A0}]|[\\x09-\\x0D\\x20\\x{00A0}]$/u', $value) === 1) {
            throw new SlugInvalidArgumentException(sprintf('%s has forbidden boundary whitespace.', $field));
        }

        if (mb_strlen($value, 'UTF-8') > $maxCodePoints) {
            throw new SlugInvalidArgumentException(sprintf('%s exceeds its maximum length.', $field));
        }
    }

    public static function assertReason(string $value, int $maxCodePoints = 500, string $field = 'reason'): void
    {
        if ($value === '' || preg_match('//u', $value) !== 1) {
            throw new SlugInvalidArgumentException(sprintf('%s must be non-empty valid UTF-8.', $field));
        }

        if (preg_match('/[\p{Cc}\p{Cs}\p{Cf}]/u', $value) === 1) {
            throw new SlugInvalidArgumentException(sprintf('%s contains a forbidden character.', $field));
        }

        if (mb_strlen($value, 'UTF-8') > $maxCodePoints) {
            throw new SlugInvalidArgumentException(sprintf('%s exceeds its maximum length.', $field));
        }
    }

    public static function assertNonNegativeRevision(?int $revision): void
    {
        if ($revision !== null && $revision < 0) {
            throw new SlugInvalidArgumentException('Expected revision must be non-negative.');
        }
    }

    public static function assertDateTimeRange(\DateTimeImmutable $value, string $field): void
    {
        $utc = $value->setTimezone(new \DateTimeZone('UTC'));
        $min = new \DateTimeImmutable('1000-01-01T00:00:00.000000Z');
        $max = new \DateTimeImmutable('9999-12-31T23:59:59.999999Z');

        if ($utc < $min || $utc > $max) {
            throw new SlugInvalidArgumentException(sprintf('%s is outside the supported UTC range.', $field));
        }
    }

    private function __construct() {}
}
