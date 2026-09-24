<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Repository\Pdo\Schema;

use PDO;
use Maatify\Slug\Lifecycle\Exception\SlugPersistenceInvariantException;
use Maatify\Slug\Lifecycle\Repository\Pdo\Support\PdoRowHydrator;

/** Verifies required package tables, columns, indexes, and foreign keys. */
final readonly class PdoSchemaVerifier
{
    /** Stores the connection whose installed schema is being checked. */
    public function __construct(private PDO $pdo) {}

    /** Fails closed when the installed schema does not match the package contract. */
    public function assertInstalled(): void
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
            throw new SlugPersistenceInvariantException('Unable to inspect the package schema.');
        }
        $rows = PdoRowHydrator::many($statement->fetchAll(PDO::FETCH_ASSOC));
        $names = array_map(static fn(array $row): string => PdoRowHydrator::string($row, 'TABLE_NAME'), $rows);
        sort($names);
        $sortedExpected = $expected;
        sort($sortedExpected);
        if ($names !== $sortedExpected) {
            throw new SlugPersistenceInvariantException('Package schema table set is not exact.');
        }
        foreach ($rows as $row) {
            if (PdoRowHydrator::string($row, 'ENGINE') !== 'InnoDB' || PdoRowHydrator::string($row, 'TABLE_COLLATION') !== 'utf8mb4_bin') {
                throw new SlugPersistenceInvariantException('Package schema does not satisfy exact InnoDB/utf8mb4 semantics.');
            }
        }

        $this->assertRequiredIndexes();
        $this->assertRequiredForeignKeys();
        $this->assertColumnContract();
    }

    /** Verifies the unique and lookup indexes required by lifecycle invariants. */
    private function assertRequiredIndexes(): void
    {
        $required = [
            'maa_slug_scopes' => ['PRIMARY', 'uk_scope_identity', 'ix_scope_profile'],
            'maa_slug_bindings' => ['PRIMARY', 'uk_binding_identity', 'uk_binding_id_scope', 'ix_binding_current_registry', 'ix_binding_status'],
            'maa_slug_operations' => ['PRIMARY', 'uk_operation_key', 'ix_operation_type_created_id'],
            'maa_slug_operation_bindings' => ['PRIMARY', 'uk_operation_binding_idempotency', 'uk_operation_binding_pair', 'uk_operation_binding_role', 'ix_operation_binding_role'],
            'maa_slug_registry' => ['PRIMARY', 'uk_registry_scope_slug', 'uk_registry_binding_slug', 'ix_registry_binding_role', 'ix_registry_scope_role_id'],
            'maa_slug_history' => ['PRIMARY', 'uk_history_binding_sequence', 'ix_history_binding_occurred_id', 'ix_history_event_occurred_id'],
        ];
        $statement = $this->pdo->query(
            "SELECT TABLE_NAME, INDEX_NAME FROM information_schema.STATISTICS "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'maa_slug_%' GROUP BY TABLE_NAME, INDEX_NAME",
        );
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to inspect package indexes.');
        }
        $actual = [];
        foreach (PdoRowHydrator::many($statement->fetchAll(PDO::FETCH_ASSOC)) as $row) {
            $table = PdoRowHydrator::string($row, 'TABLE_NAME');
            $actual[$table][] = PdoRowHydrator::string($row, 'INDEX_NAME');
        }
        foreach ($required as $table => $indexes) {
            foreach ($indexes as $index) {
                if (! in_array($index, $actual[$table] ?? [], true)) {
                    throw new SlugPersistenceInvariantException(sprintf('Required package index %s.%s is missing.', $table, $index));
                }
            }
        }
    }

    /** Verifies the foreign-key topology required by persisted lifecycle state. */
    private function assertRequiredForeignKeys(): void
    {
        $required = [
            'fk_binding_scope',
            'fk_registry_scope',
            'fk_registry_binding_scope',
            'fk_history_binding',
            'fk_history_operation',
            'fk_operation_binding_operation',
            'fk_operation_binding_binding',
        ];
        $statement = $this->pdo->query(
            "SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS "
            . "WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'maa_slug_%' AND CONSTRAINT_NAME LIKE 'fk_%'",
        );
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to inspect package foreign keys.');
        }
        $actual = array_map(
            static fn(array $row): string => PdoRowHydrator::string($row, 'CONSTRAINT_NAME'),
            PdoRowHydrator::many($statement->fetchAll(PDO::FETCH_ASSOC)),
        );
        foreach ($required as $constraint) {
            if (! in_array($constraint, $actual, true)) {
                throw new SlugPersistenceInvariantException(sprintf('Required package foreign key %s is missing.', $constraint));
            }
        }
    }

    /** Verifies required column names and SQL definitions for the installed schema. */
    private function assertColumnContract(): void
    {
        $asciiColumns = [
            'maa_slug_scopes.namespace',
            'maa_slug_scopes.profile_key',
            'maa_slug_bindings.status',
            'maa_slug_operations.operation_key',
            'maa_slug_operations.operation_type',
            'maa_slug_operations.request_fingerprint',
            'maa_slug_operations.result_type',
            'maa_slug_operations.status',
            'maa_slug_operation_bindings.participant_role',
            'maa_slug_registry.claim_role',
            'maa_slug_history.event_type',
            'maa_slug_history.scope_namespace_snapshot',
            'maa_slug_history.claim_role_snapshot',
            'maa_slug_history.previous_claim_role_snapshot',
            'maa_slug_history.related_namespace',
            'maa_slug_history.operation_key',
        ];
        $statement = $this->pdo->query(
            "SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, DATETIME_PRECISION, COLLATION_NAME, EXTRA, COLUMN_COMMENT "
            . "FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'maa_slug_%'",
        );
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to inspect package columns.');
        }
        foreach (PdoRowHydrator::many($statement->fetchAll(PDO::FETCH_ASSOC)) as $row) {
            $table = PdoRowHydrator::string($row, 'TABLE_NAME');
            $column = PdoRowHydrator::string($row, 'COLUMN_NAME');
            $dataType = strtolower(PdoRowHydrator::string($row, 'DATA_TYPE'));
            $comment = $row['COLUMN_COMMENT'] ?? null;
            if (! is_string($comment) || mb_strlen(trim($comment), 'UTF-8') < 8) {
                throw new SlugPersistenceInvariantException(sprintf('Column %s.%s must have a meaningful schema comment.', $table, $column));
            }
            if ($dataType === 'json' || str_contains(strtolower(PdoRowHydrator::string($row, 'EXTRA')), 'generated')) {
                throw new SlugPersistenceInvariantException('Package schema must not depend on native JSON or generated columns.');
            }
            if (in_array($dataType, ['char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext'], true)) {
                $collation = $row['COLLATION_NAME'] ?? null;
                $expectedCollation = in_array($table . '.' . $column, $asciiColumns, true) ? 'ascii_bin' : 'utf8mb4_bin';
                if ($collation !== $expectedCollation) {
                    throw new SlugPersistenceInvariantException(sprintf('Column %s.%s has unexpected collation.', $table, $column));
                }
            }
            if ($dataType === 'datetime' && PdoRowHydrator::nonNegativeInt($row, 'DATETIME_PRECISION') !== 6) {
                throw new SlugPersistenceInvariantException('Package timestamps must use DATETIME(6).');
            }
        }
    }
}
