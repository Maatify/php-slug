<?php

declare(strict_types=1);

namespace Maatify\Slug\Canonicalization\Mapper;

use Maatify\Slug\Exception\SlugInvalidArgumentException;

/** Converts invalid lookup canonicalization into the package's lookup result shape. */
final class LookupCanonicalizationMapper
{
    /**
     * Runs the canonicalization branch and invokes the invalid branch on validation failure.
     *
     * @template TResult
     * @param callable(): TResult $operation
     * @param callable(): TResult $invalid
     * @return TResult
     */
    public static function map(callable $operation, callable $invalid): mixed
    {
        try {
            return $operation();
        } catch (SlugInvalidArgumentException) {
            return $invalid();
        }
    }

    private function __construct() {}
}
