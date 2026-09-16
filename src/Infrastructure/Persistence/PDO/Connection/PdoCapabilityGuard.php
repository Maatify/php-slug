<?php

declare(strict_types=1);

namespace Maatify\Slug\Infrastructure\Persistence\PDO\Connection;

use PDO;
use PDOException;
use Maatify\Slug\Exception\SlugRuntimeCompatibilityException;
use Maatify\Slug\Exception\SlugUnsupportedDriverException;
use Maatify\Slug\Infrastructure\Persistence\PDO\Connection\PdoRowHydrator;
use Throwable;

final readonly class PdoCapabilityGuard
{
    public function __construct(private PDO $pdo) {}

    /**
     * Verifies connection capabilities that do not require the package schema.
     *
     * This method performs no DDL and does not mutate package tables. Runtime
     * callers must use assertInstalledSchemaSupported() before package writes.
     */
    public function assertSupported(): void
    {
        $this->assertDriverAndConnectionAttributes();
        $this->assertTransactionAndSavepointCapabilities();
        $this->assertExactStringSemantics();
        $this->assertDatetimePrecision();
    }

    /**
     * Verifies capabilities that require the installed package schema.
     *
     * Probes use real package rows inside a transaction/savepoint and leave no
     * rows or schema objects behind. No product or server version is inspected.
     */
    public function assertInstalledSchemaSupported(): void
    {
        $this->assertSupported();
        $this->assertPackageTables();
        $this->probeInstalledSchemaCapabilities();
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

    private function assertDriverAndConnectionAttributes(): void
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
    }

    private function assertConnectionCharset(): void
    {
        $statement = $this->pdo->query('SELECT @@character_set_connection');
        if ($statement === false || $statement->fetchColumn() !== 'utf8mb4') {
            throw new SlugRuntimeCompatibilityException('The injected PDO connection must use utf8mb4.');
        }
    }

    private function assertTransactionAndSavepointCapabilities(): void
    {
        $outerTransaction = $this->pdo->inTransaction();
        $startedTransaction = false;
        $savepoint = $this->savepointName('connection');
        $failure = null;

        try {
            if ($outerTransaction) {
                $this->exec($this->pdo, sprintf('SAVEPOINT %s', $savepoint), 'Savepoints are required for caller-owned transactions.');
            } else {
                if (! $this->pdo->beginTransaction()) {
                    throw new SlugRuntimeCompatibilityException('Transactions are required.');
                }
                $startedTransaction = true;
                $this->exec($this->pdo, sprintf('SAVEPOINT %s', $savepoint), 'Savepoints are required.');
            }

            if (! $this->pdo->inTransaction()) {
                throw new SlugRuntimeCompatibilityException('The connection ended the transaction during capability probing.');
            }
            $statement = $this->pdo->query('SELECT 1');
            if ($statement === false || (int) $statement->fetchColumn() !== 1) {
                throw new SlugRuntimeCompatibilityException('Transactional SELECT is required.');
            }
        } catch (SlugRuntimeCompatibilityException $exception) {
            $failure = $exception;
        } catch (Throwable $throwable) {
            $failure = new SlugRuntimeCompatibilityException('The PDO connection does not support transactions/savepoints.', 0, $throwable);
        }

        try {
            $this->cleanupProbeTransaction($outerTransaction, $startedTransaction, $savepoint);
        } catch (Throwable $cleanupFailure) {
            if ($failure !== null) {
                throw new SlugRuntimeCompatibilityException(
                    'Capability probing failed and its transaction cleanup also failed: ' . $cleanupFailure->getMessage(),
                    0,
                    $failure,
                );
            }
            throw $cleanupFailure;
        }

        if ($failure !== null) {
            throw $failure;
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

    private function assertPackageTables(): void
    {
        $expected = [
            'maa_slug_scopes',
            'maa_slug_bindings',
            'maa_slug_operations',
            'maa_slug_operation_bindings',
            'maa_slug_registry',
            'maa_slug_history',
        ];
        $statement = $this->pdo->query(
            "SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'maa_slug_%' ORDER BY TABLE_NAME",
        );
        if ($statement === false) {
            throw new SlugRuntimeCompatibilityException('Package schema metadata is unavailable.');
        }

        $rows = PdoRowHydrator::many($statement->fetchAll(PDO::FETCH_ASSOC));
        $actual = array_map(static function (array $row): string {
            $name = $row['TABLE_NAME'] ?? null;
            if (! is_string($name)) {
                throw new SlugRuntimeCompatibilityException('Package schema metadata has an invalid table name.');
            }

            return $name;
        }, $rows);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new SlugRuntimeCompatibilityException('The installed package table set is not exact.');
        }
        foreach ($rows as $row) {
            if (($row['ENGINE'] ?? null) !== 'InnoDB' || ($row['TABLE_COLLATION'] ?? null) !== 'utf8mb4_bin') {
                throw new SlugRuntimeCompatibilityException('Package tables require InnoDB and utf8mb4_bin.');
            }
        }
    }

    private function probeInstalledSchemaCapabilities(): void
    {
        $outerTransaction = $this->pdo->inTransaction();
        $startedTransaction = false;
        $savepoint = $this->savepointName('schema');
        $namespace = '__cap_' . strtolower(bin2hex(random_bytes(8)));
        $now = '2026-01-01 00:00:00.123456';
        $failure = null;

        try {
            if ($outerTransaction) {
                $this->exec($this->pdo, sprintf('SAVEPOINT %s', $savepoint), 'Unable to establish schema capability savepoint.');
            } else {
                if (! $this->pdo->beginTransaction()) {
                    throw new SlugRuntimeCompatibilityException('Unable to begin schema capability probe.');
                }
                $startedTransaction = true;
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO maa_slug_scopes (namespace, locale_key, context_key, profile_key, created_at, updated_at) '
                . 'VALUES (:namespace, \'\', \'\', \'ascii-v1\', :created_at, :updated_at)',
            );
            if ($insert === false) {
                throw new SlugRuntimeCompatibilityException('Package schema is not writable with native prepares.');
            }
            $insert->execute(['namespace' => $namespace, 'created_at' => $now, 'updated_at' => $now]);
            $scopeId = (int) $this->pdo->lastInsertId();
            if ($scopeId < 1) {
                throw new SlugRuntimeCompatibilityException('Package schema did not return a scope identity.');
            }

            $lock = $this->pdo->prepare('SELECT id FROM maa_slug_scopes WHERE id = :id FOR UPDATE');
            if ($lock === false || ! $lock->execute(['id' => $scopeId]) || (int) $lock->fetchColumn() !== $scopeId) {
                throw new SlugRuntimeCompatibilityException('Package row-lock semantics are unavailable.');
            }

            try {
                $insert->execute(['namespace' => $namespace, 'created_at' => $now, 'updated_at' => $now]);
            } catch (PDOException $exception) {
                if (! PdoDuplicateClassifier::matches($exception, 'uk_scope_identity')) {
                    throw new SlugRuntimeCompatibilityException('Package unique-key evidence is unavailable.', 0, $exception);
                }
            }

            $foreignKeyProbe = $this->pdo->prepare(
                'INSERT INTO maa_slug_bindings '
                . '(scope_id, entity_type, entity_key, current_registry_id, status, revision, history_sequence, created_at, updated_at) '
                . 'VALUES (:scope_id, \'capability\', :entity_key, NULL, \'RELEASED\', 0, 0, :created_at, :updated_at)',
            );
            if ($foreignKeyProbe === false) {
                throw new SlugRuntimeCompatibilityException('Package foreign-key probe could not be prepared.');
            }
            try {
                $foreignKeyProbe->execute([
                    'scope_id' => '9223372036854775807',
                    'entity_key' => $namespace,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (PDOException $exception) {
                if (! self::errorNumberIs($exception, 1452) || ! self::errorMentions($exception, 'fk_binding_scope')) {
                    throw new SlugRuntimeCompatibilityException('Package foreign-key enforcement is unavailable.', 0, $exception);
                }
            }
        } catch (SlugRuntimeCompatibilityException $exception) {
            $failure = $exception;
        } catch (Throwable $throwable) {
            $failure = new SlugRuntimeCompatibilityException('The installed package schema does not satisfy the RC1 capability contract.', 0, $throwable);
        }

        try {
            $this->cleanupProbeTransaction($outerTransaction, $startedTransaction, $savepoint);
        } catch (Throwable $cleanupFailure) {
            if ($failure !== null) {
                throw new SlugRuntimeCompatibilityException(
                    'Schema capability probing failed and its transaction cleanup also failed: ' . $cleanupFailure->getMessage(),
                    0,
                    $failure,
                );
            }
            throw $cleanupFailure;
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    private function cleanupProbeTransaction(bool $outerTransaction, bool $startedTransaction, string $savepoint): void
    {
        if ($startedTransaction) {
            if (! $this->pdo->inTransaction()) {
                throw new SlugRuntimeCompatibilityException('Capability probe transaction disappeared before rollback.');
            }
            try {
                if (! $this->pdo->rollBack()) {
                    throw new SlugRuntimeCompatibilityException('Capability probe rollback failed.');
                }
            } catch (SlugRuntimeCompatibilityException $exception) {
                throw $exception;
            } catch (Throwable $throwable) {
                throw new SlugRuntimeCompatibilityException('Capability probe rollback failed.', 0, $throwable);
            }
            return;
        }

        if (! $outerTransaction) {
            return;
        }
        if (! $this->pdo->inTransaction()) {
            throw new SlugRuntimeCompatibilityException('Caller transaction disappeared during capability probe cleanup.');
        }
        $this->exec($this->pdo, sprintf('ROLLBACK TO SAVEPOINT %s', $savepoint), 'Capability probe rollback to savepoint failed.');
        $this->exec($this->pdo, sprintf('RELEASE SAVEPOINT %s', $savepoint), 'Capability probe savepoint release failed.');
        if (! $this->pdo->inTransaction()) {
            throw new SlugRuntimeCompatibilityException('Caller transaction was lost during capability probe cleanup.');
        }
    }

    private function savepointName(string $prefix): string
    {
        try {
            return 'maa_slug_cap_' . $prefix . '_' . strtoupper(bin2hex(random_bytes(8)));
        } catch (Throwable $throwable) {
            throw new SlugRuntimeCompatibilityException('Unable to allocate a capability probe savepoint.', 0, $throwable);
        }
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

    private static function errorMentions(PDOException $exception, string $needle): bool
    {
        return str_contains(strtolower($exception->getMessage()), strtolower($needle));
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
