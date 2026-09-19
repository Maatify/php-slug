<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Repository\Pdo\Support;

use Maatify\Slug\Lifecycle\Exception\SlugPersistenceInvariantException;

final class PdoRowHydrator
{
    /** @return array<string, mixed>|null */
    public static function one(mixed $value): ?array
    {
        if ($value === false) {
            return null;
        }
        if (! is_array($value)) {
            throw new SlugPersistenceInvariantException('PDO row is not an associative array.');
        }
        $row = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new SlugPersistenceInvariantException('PDO row contains a non-string column name.');
            }
            $row[$key] = $item;
        }

        return $row;
    }

    /** @return list<array<string, mixed>> */
    public static function many(mixed $value): array
    {
        if (! is_array($value)) {
            throw new SlugPersistenceInvariantException('PDO rows are not an array.');
        }
        $rows = [];
        foreach ($value as $item) {
            $row = self::one($item);
            if ($row === null) {
                throw new SlugPersistenceInvariantException('PDO rows contain a false value.');
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** @param array<string, mixed> $row */
    public static function string(array $row, string $key): string
    {
        if (! array_key_exists($key, $row) || ! is_string($row[$key])) {
            throw new SlugPersistenceInvariantException(sprintf('PDO column %s must be a string.', $key));
        }

        return $row[$key];
    }

    /** @param array<string, mixed> $row */
    public static function nullableString(array $row, string $key): ?string
    {
        if (! array_key_exists($key, $row) || ($row[$key] !== null && ! is_string($row[$key]))) {
            throw new SlugPersistenceInvariantException(sprintf('PDO column %s must be a nullable string.', $key));
        }

        return $row[$key];
    }

    /** @param array<string, mixed> $row */
    public static function nonNegativeInt(array $row, string $key): int
    {
        if (! array_key_exists($key, $row)) {
            throw new SlugPersistenceInvariantException(sprintf('PDO column %s is missing.', $key));
        }
        $value = $row[$key];
        if (is_int($value)) {
            return $value >= 0 ? $value : throw new SlugPersistenceInvariantException(sprintf('PDO column %s must be non-negative.', $key));
        }
        if (is_string($value) && preg_match('/\A\d+\z/', $value) === 1) {
            $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($integer !== false) {
                return $integer;
            }
        }

        throw new SlugPersistenceInvariantException(sprintf('PDO column %s must be a non-negative integer.', $key));
    }

    private function __construct() {}
}
