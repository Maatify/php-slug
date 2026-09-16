<?php

declare(strict_types=1);

namespace Maatify\Slug\Command;

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
            \Maatify\Slug\Identity\IdentityValidator::assertDateTimeRange($value, $field);
        }
    }

    private function __construct() {}
}
