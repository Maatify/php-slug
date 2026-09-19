<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Support;

use PDO;

/**
 * Real MySQL connection with one-shot boundary failures for system evidence.
 */
final class FaultInjectingMySqlPdo extends PDO
{
    public bool $failNextBegin = false;

    public bool $failNextPackageSavepoint = false;

    public function __construct()
    {
        parent::__construct(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                (string) getenv('SLUG_TEST_DB_HOST'),
                (int) getenv('SLUG_TEST_DB_PORT'),
                (string) getenv('SLUG_TEST_DB_NAME'),
            ),
            (string) getenv('SLUG_TEST_DB_USER'),
            (string) getenv('SLUG_TEST_DB_PASSWORD'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
        $this->exec('SET NAMES utf8mb4 COLLATE utf8mb4_bin');
    }

    public function beginTransaction(): bool
    {
        if ($this->failNextBegin) {
            $this->failNextBegin = false;

            return false;
        }

        return parent::beginTransaction();
    }

    public function exec(string $statement): int|false
    {
        if ($this->failNextPackageSavepoint && str_starts_with($statement, 'SAVEPOINT maa_slug_sp_')) {
            $this->failNextPackageSavepoint = false;

            return false;
        }

        return parent::exec($statement);
    }
}
