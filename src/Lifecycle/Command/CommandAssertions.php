<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Command;

use Maatify\Slug\Exception\SlugInvalidArgumentException;

final class CommandAssertions
{
    public static function nonEmpty(string $value, string $field): void
    {
        if ($value === '') {
            throw new SlugInvalidArgumentException(sprintf('%s must be non-empty.', $field));
        }
    }

    public static function revision(?int $value, string $field = 'expectedRevision'): void
    {
        if ($value !== null && $value < 0) {
            throw new SlugInvalidArgumentException(sprintf('%s must be non-negative.', $field));
        }
    }

    public static function dateTime(?\DateTimeImmutable $value, string $field): void
    {
        if ($value !== null) {
            $utc = $value->setTimezone(new \DateTimeZone('UTC'));
            $min = new \DateTimeImmutable('1000-01-01T00:00:00.000000Z');
            $max = new \DateTimeImmutable('9999-12-31T23:59:59.999999Z');
            if ($utc < $min || $utc > $max) {
                throw new SlugInvalidArgumentException(sprintf('%s is outside the supported UTC range.', $field));
            }
        }
    }

    private function __construct() {}
}
