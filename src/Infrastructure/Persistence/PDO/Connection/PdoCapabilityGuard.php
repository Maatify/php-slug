<?php

declare(strict_types=1);

namespace Maatify\Slug\Infrastructure\Persistence\PDO\Connection;

use PDO;
use PDOException;
use Maatify\Slug\Exception\SlugRuntimeCompatibilityException;
use Maatify\Slug\Exception\SlugUnsupportedDriverException;
use Throwable;

final readonly class PdoCapabilityGuard
{
    public function __construct(private PDO $pdo) {}

    /**
     * Runs read/rollback-scoped probes and fails closed before package mutation.
     * No product or server version is inspected.
     */
    public function assertSupported(): void
    {
        $driver = $this->driverName();
        if ($driver !== 'mysql') {
            throw new SlugUnsupportedDriverException(sprintf('RC1 requires the pdo_mysql driver; got %s.', $driver ?? 'unknown'));
        }

        if (! extension_loaded('pdo_mysql')) {
            throw new SlugRuntimeCompatibilityException('The pdo_mysql extension is unavailable.');
        }

        try {
            if ($this->pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
                throw new SlugRuntimeCompatibilityException('PDO ERRMODE_EXCEPTION is required.');
            }
            $emulatePrepares = $this->pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
            if ($emulatePrepares !== false && $emulatePrepares !== 0 && $emulatePrepares !== '0') {
                throw new SlugRuntimeCompatibilityException('Native PDO prepares are required.');
            }
            $this->assertConnectionCharset();
        } catch (SlugRuntimeCompatibilityException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            throw new SlugRuntimeCompatibilityException('Required PDO connection attributes are unavailable.', 0, $throwable);
        }

        $this->probeTransactionalCapabilities();
    }

    public function driverName(): ?string
    {
        try {
            $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (Throwable) {
            return null;
        }

        return is_string($driver) ? $driver : null;
    }

    private function assertConnectionCharset(): void
    {
        $statement = $this->pdo->query('SELECT @@character_set_connection');
        if ($statement === false || $statement->fetchColumn() !== 'utf8mb4') {
            throw new SlugRuntimeCompatibilityException('The injected PDO connection must use utf8mb4.');
        }
    }

    private function probeTransactionalCapabilities(): void
    {
        $startedTransaction = false;
        try {
            $savepoint = 'maa_slug_capability_probe_' . strtoupper(bin2hex(random_bytes(8)));
        } catch (Throwable $throwable) {
            throw new SlugRuntimeCompatibilityException('Unable to allocate a capability probe savepoint.', 0, $throwable);
        }

        try {
            if ($this->pdo->inTransaction()) {
                $this->exec($this->pdo, sprintf('SAVEPOINT %s', $savepoint), 'Savepoints are required for caller-owned transactions.');
            } else {
                if (! $this->pdo->beginTransaction()) {
                    throw new SlugRuntimeCompatibilityException('Transactions are required.');
                }
                $startedTransaction = true;
                $this->exec($this->pdo, sprintf('SAVEPOINT %s', $savepoint), 'Savepoints are required.');
            }

            $this->assertRowLock();
            $this->assertExactStringSemantics();
            $this->assertDatetimePrecision();
            $this->assertUniqueAndDuplicateEvidence();
            $this->assertForeignKeyEvidence();
        } catch (SlugRuntimeCompatibilityException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            throw new SlugRuntimeCompatibilityException('The PDO connection does not satisfy the RC1 capability contract.', 0, $throwable);
        } finally {
            $this->dropProbeTables();

            if ($startedTransaction) {
                try {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                } catch (Throwable) {
                }
            } elseif ($this->pdo->inTransaction()) {
                try {
                    $this->pdo->exec(sprintf('ROLLBACK TO SAVEPOINT %s', $savepoint));
                } catch (Throwable) {
                }
                try {
                    $this->pdo->exec(sprintf('RELEASE SAVEPOINT %s', $savepoint));
                } catch (Throwable) {
                }
            }
        }
    }

    private function assertRowLock(): void
    {
        $statement = $this->pdo->query('SELECT 1 FOR UPDATE');
        if ($statement === false || (int) $statement->fetchColumn() !== 1) {
            throw new SlugRuntimeCompatibilityException('Row locking with SELECT ... FOR UPDATE is required.');
        }
    }

    private function assertExactStringSemantics(): void
    {
        $statement = $this->pdo->prepare(
            'SELECT CASE WHEN CAST(:exact_a AS BINARY) = CAST(:exact_b AS BINARY) '
            . 'AND CAST(:different_a AS BINARY) <> CAST(:different_b AS BINARY) '
            . 'THEN 1 ELSE 0 END',
        );
        if ($statement === false) {
            throw new SlugRuntimeCompatibilityException('Exact-safe prepared string comparison is required.');
        }
        $statement->execute([
            'exact_a' => 'Aa',
            'exact_b' => 'Aa',
            'different_a' => 'Aa',
            'different_b' => 'aa',
        ]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new SlugRuntimeCompatibilityException('Exact-safe binary string comparison is required.');
        }
    }

    private function assertDatetimePrecision(): void
    {
        $statement = $this->pdo->prepare(
            "SELECT DATE_FORMAT(CAST(:datetime_value AS DATETIME(6)), '%Y-%m-%dT%H:%i:%s.%f')",
        );
        if ($statement === false || ! $statement->execute(['datetime_value' => '2026-01-01 01:02:03.123456'])) {
            throw new SlugRuntimeCompatibilityException('DATETIME(6) is required.');
        }
        if ($statement->fetchColumn() !== '2026-01-01T01:02:03.123456') {
            throw new SlugRuntimeCompatibilityException('Six-microsecond DATETIME precision is required.');
        }
    }

    private function assertUniqueAndDuplicateEvidence(): void
    {
        $this->exec($this->pdo, 'CREATE TEMPORARY TABLE maa_slug_capability_unique (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB', 'Unique constraints are required.');
        $this->pdo->exec('INSERT INTO maa_slug_capability_unique (id) VALUES (1)');

        try {
            $this->pdo->exec('INSERT INTO maa_slug_capability_unique (id) VALUES (1)');
        } catch (PDOException $exception) {
            if (! self::errorNumberIs($exception, 1062)) {
                throw new SlugRuntimeCompatibilityException('pdo_mysql duplicate-key evidence 1062 is required.', 0, $exception);
            }

            return;
        }

        throw new SlugRuntimeCompatibilityException('Unique constraints did not produce duplicate-key evidence.');
    }

    private function assertForeignKeyEvidence(): void
    {
        $this->exec($this->pdo, 'CREATE TEMPORARY TABLE maa_slug_capability_parent (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB', 'Foreign keys are required.');
        $this->exec($this->pdo, 'CREATE TEMPORARY TABLE maa_slug_capability_child (parent_id INT NOT NULL, CONSTRAINT fk_capability_parent FOREIGN KEY (parent_id) REFERENCES maa_slug_capability_parent (id)) ENGINE=InnoDB', 'Foreign keys are required.');
        try {
            $this->pdo->exec('INSERT INTO maa_slug_capability_child (parent_id) VALUES (999)');
        } catch (PDOException $exception) {
            if (! self::errorNumberIs($exception, 1452)) {
                throw new SlugRuntimeCompatibilityException('Foreign-key enforcement is unavailable.', 0, $exception);
            }

            return;
        }

        throw new SlugRuntimeCompatibilityException('Foreign-key enforcement did not reject an invalid reference.');
    }

    private static function errorNumberIs(PDOException $exception, int $expected): bool
    {
        $errorInfo = $exception->errorInfo;
        if (! is_array($errorInfo) || ! array_key_exists(1, $errorInfo)) {
            return false;
        }
        $actual = $errorInfo[1];
        return (is_int($actual) && $actual === $expected) || (is_string($actual) && $actual === (string) $expected);
    }

    private function dropProbeTables(): void
    {
        foreach (['maa_slug_capability_child', 'maa_slug_capability_parent', 'maa_slug_capability_unique'] as $table) {
            try {
                $this->pdo->exec(sprintf('DROP TEMPORARY TABLE IF EXISTS %s', $table));
            } catch (Throwable) {
            }
        }
    }

    private function exec(PDO $pdo, string $sql, string $message): void
    {
        try {
            if ($pdo->exec($sql) === false) {
                throw new SlugRuntimeCompatibilityException($message);
            }
        } catch (SlugRuntimeCompatibilityException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            throw new SlugRuntimeCompatibilityException($message, 0, $throwable);
        }
    }
}
