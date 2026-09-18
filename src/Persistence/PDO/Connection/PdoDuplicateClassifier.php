<?php

declare(strict_types=1);

namespace Maatify\Slug\Persistence\PDO\Connection;

use PDOException;

final class PdoDuplicateClassifier
{
    public static function matches(PDOException $exception, string $constraint): bool
    {
        $errorInfo = $exception->errorInfo;
        if (! is_array($errorInfo) || ! array_key_exists(1, $errorInfo)) {
            return false;
        }
        $errorNumber = $errorInfo[1];
        if (! ((is_int($errorNumber) && $errorNumber === 1062) || (is_string($errorNumber) && $errorNumber === '1062'))) {
            return false;
        }

        $detail = array_key_exists(2, $errorInfo) && is_string($errorInfo[2]) ? $errorInfo[2] : '';
        return str_contains($exception->getMessage(), $constraint) || str_contains($detail, $constraint);
    }

    private function __construct() {}
}
