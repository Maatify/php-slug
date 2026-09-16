<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Integration\Persistence\Scope;

use Maatify\Slug\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Identity\EntityReference;
use Maatify\Slug\Identity\SlugProfileKey;
use Maatify\Slug\Infrastructure\Persistence\PDO\Connection\PdoCapabilityGuard;
use Maatify\Slug\Internal\Transaction\PdoTransactionCoordinator;
use Maatify\Slug\Infrastructure\Persistence\PDO\Scope\PdoScopeRepository;
use Maatify\Slug\Exception\SlugPersistenceInvariantException;
use Maatify\Slug\Exception\SlugScopeProfileMismatchException;
use Maatify\Slug\Profile\Registry\SlugProfileRegistry;
use Maatify\Slug\Scope\Value\SlugScope;
use Maatify\Slug\Tests\Integration\Schema\MySqlIntegrationTestCase;
use Maatify\Slug\Tests\Unit\Profile\StubSlugProfile;

final class PdoScopePersistenceTest extends MySqlIntegrationTestCase
{
    public function testScopeAndReleasedBindingPlaceholderBootstrapAtomically(): void
    {
        $profile = new StubSlugProfile(new SlugProfileKey('ascii-v1'));
        $profiles = new SlugProfileRegistry();
        $profiles->register($profile);
        $repository = new PdoScopeRepository($this->pdo, $profiles, $this->clock(), new PdoCapabilityGuard($this->pdo));
        $request = new ScopeProfileRequestDTO(new SlugScope('catalog', null, null), new SlugProfileKey('ascii-v1'));

        $scope = $repository->ensureScope($request);
        $binding = $repository->ensureBindingPlaceholder($request, new EntityReference('product', '42'));
        $sameScope = $repository->ensureScope($request);
        $sameBinding = $repository->ensureBindingPlaceholder($request, new EntityReference('product', '42'));

        self::assertSame('catalog', $scope->scope->namespace);
        self::assertSame($scope->id, $sameScope->id);
        self::assertGreaterThan(0, $binding->id);
        self::assertSame($binding->id, $sameBinding->id);
        self::assertNull($scope->scope->localeKey);
        self::assertNull($scope->scope->contextKey);
        self::assertSame('RELEASED', $binding->state->status->value);
        self::assertNull($binding->state->currentSlug);
        self::assertNull($binding->currentClaim);
        self::assertSame(0, $binding->state->revision);
        self::assertSame(0, $binding->state->historySequence);
    }

    public function testPackageOwnedFailureRollsBackAndCallerOwnedFailureUsesSavepoint(): void
    {
        $coordinator = new PdoTransactionCoordinator($this->pdo);
        try {
            $coordinator->run(function (): void {
                $this->pdo->exec("INSERT INTO maa_slug_scopes (namespace, locale_key, context_key, profile_key, created_at, updated_at) VALUES ('rollback', '', '', 'ascii-v1', '2026-01-01 00:00:00.123456', '2026-01-01 00:00:00.123456')");
                throw new \RuntimeException('package failure');
            });
        } catch (\RuntimeException $exception) {
            self::assertSame('package failure', $exception->getMessage());
        }
        $statement = $this->pdo->query("SELECT COUNT(*) FROM maa_slug_scopes WHERE namespace = 'rollback'");
        self::assertNotFalse($statement);
        self::assertContains($statement->fetchColumn(), [0, '0']);

        $this->pdo->beginTransaction();
        try {
            try {
                $coordinator->run(function (): void {
                    $this->pdo->exec("INSERT INTO maa_slug_scopes (namespace, locale_key, context_key, profile_key, created_at, updated_at) VALUES ('savepoint', '', '', 'ascii-v1', '2026-01-01 00:00:00.123456', '2026-01-01 00:00:00.123456')");
                    throw new \RuntimeException('savepoint failure');
                });
            } catch (\RuntimeException $exception) {
                self::assertSame('savepoint failure', $exception->getMessage());
            }
            self::assertTrue($this->pdo->inTransaction());
            $statement = $this->pdo->query("SELECT COUNT(*) FROM maa_slug_scopes WHERE namespace = 'savepoint'");
            self::assertNotFalse($statement);
            self::assertContains($statement->fetchColumn(), [0, '0']);
            $this->pdo->rollBack();
        } catch (\Throwable $throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $throwable;
        }
    }

    public function testExistingScopeProfileMismatchFailsBeforeBindingMutation(): void
    {
        $ascii = new SlugProfileKey('ascii-v1');
        $other = new SlugProfileKey('custom-v1');
        $profiles = new SlugProfileRegistry();
        $profiles->register(new StubSlugProfile($ascii));
        $profiles->register(new StubSlugProfile($other));
        $repository = new PdoScopeRepository($this->pdo, $profiles, $this->clock(), new PdoCapabilityGuard($this->pdo));

        $scope = new SlugScope('catalog', null, null);
        $repository->ensureScope(new ScopeProfileRequestDTO($scope, $ascii));

        $this->expectException(SlugScopeProfileMismatchException::class);
        $repository->ensureBindingPlaceholder(new ScopeProfileRequestDTO($scope, $other), new EntityReference('product', 'mismatch'));
    }

    public function testReleasedBindingWithLiveClaimIsRejectedAsCorrupt(): void
    {
        $profiles = new SlugProfileRegistry();
        $profiles->register(new StubSlugProfile(new SlugProfileKey('ascii-v1')));
        $repository = new PdoScopeRepository($this->pdo, $profiles, $this->clock(), new PdoCapabilityGuard($this->pdo));
        $request = new ScopeProfileRequestDTO(new SlugScope('catalog', null, null), new SlugProfileKey('ascii-v1'));
        $binding = $repository->ensureBindingPlaceholder($request, new EntityReference('product', 'released-corrupt'));

        $statement = $this->pdo->prepare(
            'INSERT INTO maa_slug_registry (scope_id, binding_id, slug, claim_role, claimed_at, updated_at) '
            . 'SELECT scope_id, id, :slug, :role, :claimed_at, :updated_at FROM maa_slug_bindings WHERE id = :binding_id',
        );
        self::assertNotFalse($statement);
        $statement->execute([
            'slug' => 'corrupt',
            'role' => 'CURRENT_CANONICAL',
            'claimed_at' => '2026-01-01 00:00:00.123456',
            'updated_at' => '2026-01-01 00:00:00.123456',
            'binding_id' => $binding->id,
        ]);

        $this->expectException(SlugPersistenceInvariantException::class);
        $repository->findBinding($request, new EntityReference('product', 'released-corrupt'));
    }

    public function testActiveAndInactiveBindingsWithoutCurrentPointerAreRejectedAsCorrupt(): void
    {
        $profiles = new SlugProfileRegistry();
        $profiles->register(new StubSlugProfile(new SlugProfileKey('ascii-v1')));
        $repository = new PdoScopeRepository($this->pdo, $profiles, $this->clock(), new PdoCapabilityGuard($this->pdo));
        foreach (['ACTIVE', 'INACTIVE'] as $status) {
            $request = new ScopeProfileRequestDTO(new SlugScope('catalog-' . strtolower($status), null, null), new SlugProfileKey('ascii-v1'));
            $entity = new EntityReference('product', strtolower($status) . '-corrupt');
            $binding = $repository->ensureBindingPlaceholder($request, $entity);

            $statement = $this->pdo->prepare('UPDATE maa_slug_bindings SET status = :status WHERE id = :binding_id');
            self::assertNotFalse($statement);
            $statement->execute(['status' => $status, 'binding_id' => $binding->id]);

            try {
                $repository->findBinding($request, $entity);
                self::fail($status . ' without a current pointer must be rejected.');
            } catch (SlugPersistenceInvariantException) {
            }
        }
    }

    public function testCallerOuterTransactionCanContinueAfterSavepointFailureAndCanCommitPackageWrites(): void
    {
        $this->pdo->beginTransaction();
        try {
            $coordinator = new PdoTransactionCoordinator($this->pdo);
            try {
                $coordinator->run(function (): void {
                    $this->pdo->exec("INSERT INTO maa_slug_scopes (namespace, locale_key, context_key, profile_key, created_at, updated_at) VALUES ('outer-failure', '', '', 'ascii-v1', '2026-01-01 00:00:00.123456', '2026-01-01 00:00:00.123456')");
                    throw new \RuntimeException('outer package failure');
                });
            } catch (\RuntimeException $exception) {
                self::assertSame('outer package failure', $exception->getMessage());
            }

            self::assertTrue($this->pdo->inTransaction());
            $this->pdo->exec("INSERT INTO maa_slug_scopes (namespace, locale_key, context_key, profile_key, created_at, updated_at) VALUES ('outer-success', '', '', 'ascii-v1', '2026-01-01 00:00:00.123456', '2026-01-01 00:00:00.123456')");
            $this->pdo->commit();
        } catch (\Throwable $throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $throwable;
        }

        $statement = $this->pdo->query("SELECT namespace FROM maa_slug_scopes WHERE namespace IN ('outer-failure', 'outer-success') ORDER BY namespace");
        self::assertNotFalse($statement);
        self::assertSame(['outer-success'], $statement->fetchAll(\PDO::FETCH_COLUMN));
    }
}
