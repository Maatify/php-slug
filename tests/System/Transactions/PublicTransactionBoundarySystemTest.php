<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\System\Transactions;

use PDO;
use Maatify\Slug\Lifecycle\Command\AssignExactCommand;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Registry\DTO\BindingIdentityDTO;
use Maatify\Slug\Scope\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Engine\SlugEngine;
use Maatify\Slug\Engine\SlugEngineFactory;
use Maatify\Slug\Exception\SlugReservedException;
use Maatify\Slug\Exception\SlugTransactionParticipationException;
use Maatify\Slug\Registry\Value\EntityReference;
use Maatify\Slug\Profile\Value\SlugProfileKey;
use Maatify\Slug\Profile\Registry\SlugProfileRegistry;
use Maatify\Slug\Scope\Value\SlugScope;
use Maatify\Slug\Tests\Integration\Schema\MySqlIntegrationTestCase;
use Maatify\Slug\Tests\Support\FaultInjectingMySqlPdo;
use Maatify\Slug\Tests\Unit\Allocation\TestReservedSlugPolicy;
use Maatify\Slug\Tests\Unit\Allocation\TestSlugProfile;

final class PublicTransactionBoundarySystemTest extends MySqlIntegrationTestCase
{
    public function testCallerOwnedPublicMutationUsesSavepointWithoutEndingOuterTransaction(): void
    {
        $engine = $this->engine();
        $first = $this->identity('caller-savepoint', 'first');
        $second = $this->identity('caller-savepoint', 'second');
        $observer = $this->newTestConnection();

        self::assertTrue($this->pdo->beginTransaction());
        try {
            $engine->assignExact(new AssignExactCommand($first, 'first', null, new AuditContextDTO()));

            self::assertTrue($this->pdo->inTransaction());
            self::assertSame(0, $this->scalarIntOn($observer, 'SELECT COUNT(*) FROM maa_slug_registry'));
            self::assertSame(0, $this->scalarIntOn($observer, 'SELECT COUNT(*) FROM maa_slug_bindings'));

            $engine->assignExact(new AssignExactCommand($second, 'second', null, new AuditContextDTO()));
            self::assertTrue($this->pdo->inTransaction());
            self::assertTrue($this->pdo->commit());
        } catch (\Throwable $throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $throwable;
        }

        self::assertSame(2, $this->scalarIntOn($observer, 'SELECT COUNT(*) FROM maa_slug_bindings'));
        self::assertSame(2, $this->scalarIntOn($observer, 'SELECT COUNT(*) FROM maa_slug_registry'));
        self::assertSame(2, $this->scalarIntOn($observer, 'SELECT COUNT(*) FROM maa_slug_history'));
    }

    public function testHostCatchAfterPublicPackageFailureCanContinueOuterTransactionWithoutPartialRows(): void
    {
        $engine = $this->engine(new TestReservedSlugPolicy(['reserved-public-failure']));
        $failedNamespace = 'caller-catch-failed';
        $successfulNamespace = 'caller-catch-successful';
        $failedEntityKey = 'failed';
        $successfulEntityKey = 'successful';
        $failedOperationKey = 'caller-catch-failed-operation';
        $failed = $this->identity($failedNamespace, $failedEntityKey);
        $successful = $this->identity($successfulNamespace, $successfulEntityKey);
        $observer = $this->newTestConnection();

        self::assertTrue($this->pdo->beginTransaction());
        try {
            try {
                $engine->assignExact(new AssignExactCommand(
                    $failed,
                    'reserved-public-failure',
                    null,
                    new AuditContextDTO(idempotencyKey: $failedOperationKey),
                ));
                self::fail('The reserved public mutation unexpectedly succeeded.');
            } catch (SlugReservedException) {
                self::assertTrue($this->pdo->inTransaction());

                self::assertSame(0, $this->scalarInt(
                    'SELECT COUNT(*) FROM maa_slug_scopes WHERE namespace = :namespace',
                    ['namespace' => $failedNamespace],
                ));
                self::assertSame(0, $this->scalarInt(
                    'SELECT COUNT(*) FROM maa_slug_bindings b '
                    . 'INNER JOIN maa_slug_scopes s ON s.id = b.scope_id '
                    . 'WHERE s.namespace = :namespace AND b.entity_type = :entity_type AND b.entity_key = :entity_key',
                    ['namespace' => $failedNamespace, 'entity_type' => 'product', 'entity_key' => $failedEntityKey],
                ));
                self::assertSame(0, $this->scalarInt(
                    'SELECT COUNT(*) FROM maa_slug_registry r '
                    . 'INNER JOIN maa_slug_scopes s ON s.id = r.scope_id '
                    . 'WHERE s.namespace = :namespace AND r.slug = :slug',
                    ['namespace' => $failedNamespace, 'slug' => 'reserved-public-failure'],
                ));
                self::assertSame(0, $this->scalarInt(
                    'SELECT COUNT(*) FROM maa_slug_history h '
                    . 'INNER JOIN maa_slug_bindings b ON b.id = h.binding_id '
                    . 'INNER JOIN maa_slug_scopes s ON s.id = b.scope_id '
                    . 'WHERE s.namespace = :namespace AND b.entity_type = :entity_type AND b.entity_key = :entity_key',
                    ['namespace' => $failedNamespace, 'entity_type' => 'product', 'entity_key' => $failedEntityKey],
                ));
                self::assertSame(0, $this->scalarInt(
                    'SELECT COUNT(*) FROM maa_slug_operation_bindings WHERE idempotency_key = :idempotency_key',
                    ['idempotency_key' => $failedOperationKey],
                ));
                self::assertSame(0, $this->scalarInt(
                    'SELECT COUNT(*) FROM maa_slug_operations o '
                    . 'INNER JOIN maa_slug_operation_bindings p ON p.operation_id = o.id '
                    . 'WHERE p.idempotency_key = :idempotency_key',
                    ['idempotency_key' => $failedOperationKey],
                ));
            }

            $engine->assignExact(new AssignExactCommand($successful, 'successful', null, new AuditContextDTO()));
            self::assertTrue($this->pdo->inTransaction());
            self::assertTrue($this->pdo->commit());
        } catch (\Throwable $throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $throwable;
        }

        self::assertSame(0, $this->scalarIntOn($observer, 'SELECT COUNT(*) FROM maa_slug_scopes WHERE namespace = :namespace', ['namespace' => $failedNamespace]));
        self::assertSame(1, $this->scalarIntOn($observer, 'SELECT COUNT(*) FROM maa_slug_scopes WHERE namespace = :namespace', ['namespace' => $successfulNamespace]));
        self::assertSame(0, $this->scalarIntOn($observer, 'SELECT COUNT(*) FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => $failedEntityKey]));
        self::assertSame(1, $this->scalarIntOn($observer, 'SELECT COUNT(*) FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => $successfulEntityKey]));
        self::assertSame(1, $this->scalarIntOn($observer, 'SELECT COUNT(*) FROM maa_slug_registry WHERE slug = :slug', ['slug' => 'successful']));
        self::assertSame(1, $this->scalarIntOn($observer, 'SELECT COUNT(*) FROM maa_slug_history'));
        self::assertSame(0, $this->scalarIntOn($observer, 'SELECT COUNT(*) FROM maa_slug_operations'));
        self::assertSame(0, $this->scalarIntOn($observer, 'SELECT COUNT(*) FROM maa_slug_operation_bindings WHERE idempotency_key = :idempotency_key', ['idempotency_key' => $failedOperationKey]));
    }

    public function testHostOuterRollbackRemovesSuccessfulPublicMutation(): void
    {
        $engine = $this->engine();
        $identity = $this->identity('host-rollback-public', 'product');
        $observer = $this->newTestConnection();

        self::assertTrue($this->pdo->beginTransaction());
        try {
            $result = $engine->assignExact(new AssignExactCommand($identity, 'rollback-public', null, new AuditContextDTO()));

            self::assertSame('rollback-public', $result->currentSlug?->value);
            self::assertTrue($this->pdo->inTransaction());
            self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE slug = :slug', ['slug' => 'rollback-public']));
            self::assertSame(0, $this->scalarIntOn($observer, 'SELECT COUNT(*) FROM maa_slug_registry WHERE slug = :slug', ['slug' => 'rollback-public']));
            self::assertTrue($this->pdo->rollBack());
        } catch (\Throwable $throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $throwable;
        }

        self::assertSame(0, $this->scalarIntOn($observer, 'SELECT COUNT(*) FROM maa_slug_scopes WHERE namespace = :namespace', ['namespace' => 'host-rollback-public']));
        self::assertSame(0, $this->scalarIntOn($observer, 'SELECT COUNT(*) FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'product']));
        self::assertSame(0, $this->scalarIntOn($observer, 'SELECT COUNT(*) FROM maa_slug_registry WHERE slug = :slug', ['slug' => 'rollback-public']));
        self::assertSame(0, $this->scalarIntOn($observer, 'SELECT COUNT(*) FROM maa_slug_history'));
        self::assertSame(0, $this->scalarIntOn($observer, 'SELECT COUNT(*) FROM maa_slug_operations'));
    }

    public function testPublicTransactionBoundaryFailuresLeavePackageRowsUntouched(): void
    {
        foreach (['begin', 'savepoint'] as $failure) {
            $pdo = new FaultInjectingMySqlPdo();
            $engine = $this->engine(null, $pdo);
            $engine->ensureScope(new ScopeProfileRequestDTO(new SlugScope('boundary-warm-' . $failure, null, null), new SlugProfileKey('ascii-v1')));
            $before = $this->packageCounts($this->pdo);

            if ($failure === 'begin') {
                $pdo->failNextBegin = true;
            } else {
                self::assertTrue($pdo->beginTransaction());
                $pdo->failNextPackageSavepoint = true;
            }

            try {
                $engine->assignExact(new AssignExactCommand(
                    $this->identity('boundary-' . $failure, 'product'),
                    'boundary-' . $failure,
                    null,
                    new AuditContextDTO(),
                ));
                self::fail('The injected ' . $failure . ' boundary failure was not raised.');
            } catch (SlugTransactionParticipationException) {
                if ($failure === 'savepoint') {
                    self::assertTrue($pdo->inTransaction(), 'The host transaction must remain available after savepoint setup failure.');
                }
            } finally {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }

            self::assertSame($before, $this->packageCounts($this->pdo));
        }
    }

    private function engine(?TestReservedSlugPolicy $policy = null, ?PDO $pdo = null): SlugEngine
    {
        $profiles = new SlugProfileRegistry();
        $profiles->register(new TestSlugProfile());

        return SlugEngineFactory::create($pdo ?? $this->pdo, $profiles, $policy ?? new TestReservedSlugPolicy(), $this->clock());
    }

    private function identity(string $namespace, string $entityKey): BindingIdentityDTO
    {
        return new BindingIdentityDTO(
            new ScopeProfileRequestDTO(new SlugScope($namespace, null, null), new SlugProfileKey('ascii-v1')),
            new EntityReference('product', $entityKey),
        );
    }

    /** @return array<string, int> */
    private function packageCounts(PDO $pdo): array
    {
        $counts = [];
        foreach (['maa_slug_scopes', 'maa_slug_bindings', 'maa_slug_registry', 'maa_slug_history', 'maa_slug_operations', 'maa_slug_operation_bindings'] as $table) {
            $statement = $pdo->query('SELECT COUNT(*) FROM ' . $table);
            self::assertNotFalse($statement);
            $counts[$table] = (int) $statement->fetchColumn();
        }

        return $counts;
    }

    /** @param array<string, int|string> $parameters */
    private function scalarInt(string $sql, array $parameters = []): int
    {
        return $this->scalarIntOn($this->pdo, $sql, $parameters);
    }

    /** @param array<string, int|string> $parameters */
    private function scalarIntOn(PDO $pdo, string $sql, array $parameters = []): int
    {
        $statement = $pdo->prepare($sql);
        self::assertNotFalse($statement);
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }
}
