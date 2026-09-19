<?php

declare(strict_types=1);

namespace Maatify\Slug\Canonicalization\Mapper;

use Maatify\Slug\Exception\SlugInvalidArgumentException;

final class LookupCanonicalizationMapper
{
    /**
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
