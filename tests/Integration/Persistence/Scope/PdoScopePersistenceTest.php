<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Integration\Persistence\Scope;

use Maatify\Slug\Scope\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Registry\Value\EntityReference;
use Maatify\Slug\Profile\Value\SlugProfileKey;
use Maatify\Slug\Persistence\PDO\Connection\PdoCapabilityGuard;
use Maatify\Slug\Persistence\Transaction\PdoTransactionCoordinator;
use Maatify\Slug\Persistence\PDO\Scope\PdoScopeRepository;
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

    public function testFirstUseSameProfileScopeRaceProducesOneCompleteScope(): void
    {
        $results = $this->runFirstUseScopeRace(false);
        $successful = array_values(array_filter($results, static fn(array $result): bool => ($result['ok'] ?? false) === true));

        self::assertCount(2, $successful);
        self::assertSame($successful[0]['id'], $successful[1]['id']);
        self::assertSame('ascii-v1', $successful[0]['profile']);

        $statement = $this->pdo->query("SELECT COUNT(*) FROM maa_slug_scopes WHERE namespace = 'race-same-profile'");
        self::assertNotFalse($statement);
        self::assertSame('1', (string) $statement->fetchColumn());
        $statement = $this->pdo->query('SELECT COUNT(*) FROM maa_slug_bindings');
        self::assertNotFalse($statement);
        self::assertSame('0', (string) $statement->fetchColumn());
    }

    public function testFirstUseConflictingProfileScopeRaceLeavesOneAuthorityAndRejectsTheOther(): void
    {
        $results = $this->runFirstUseScopeRace(true);
        $successful = array_values(array_filter($results, static fn(array $result): bool => ($result['ok'] ?? false) === true));
        $mismatches = array_values(array_filter($results, static fn(array $result): bool => ($result['class'] ?? null) === SlugScopeProfileMismatchException::class));

        self::assertCount(1, $successful);
        self::assertCount(1, $mismatches);
        self::assertContains($successful[0]['profile'], ['ascii-v1', 'custom-v1']);

        $statement = $this->pdo->query("SELECT profile_key, COUNT(*) AS total FROM maa_slug_scopes WHERE namespace = 'race-conflicting-profile' GROUP BY profile_key");
        self::assertNotFalse($statement);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertContains($row['total'], [1, '1']);
        self::assertSame($successful[0]['profile'], $row['profile_key']);
        $statement = $this->pdo->query('SELECT COUNT(*) FROM maa_slug_bindings');
        self::assertNotFalse($statement);
        self::assertSame('0', (string) $statement->fetchColumn());
    }

    public function testHostOuterRollbackRemovesSuccessfulPackageScopeAndBindingBootstrap(): void
    {
        $profiles = new SlugProfileRegistry();
        $profiles->register(new StubSlugProfile(new SlugProfileKey('ascii-v1')));
        $repository = new PdoScopeRepository($this->pdo, $profiles, $this->clock(), new PdoCapabilityGuard($this->pdo));
        $request = new ScopeProfileRequestDTO(new SlugScope('host-rollback', null, null), new SlugProfileKey('ascii-v1'));
        $independent = $this->newTestConnection();

        self::assertTrue($this->pdo->beginTransaction());
        try {
            $binding = $repository->ensureBindingPlaceholder($request, new EntityReference('product', 'host-rollback'));
            self::assertGreaterThan(0, $binding->id);
            self::assertTrue($this->pdo->inTransaction());
            self::assertSame('0', $this->countRows($independent, "SELECT COUNT(*) FROM maa_slug_scopes WHERE namespace = 'host-rollback'"));
            self::assertSame('0', $this->countRows($independent, "SELECT COUNT(*) FROM maa_slug_bindings WHERE entity_key = 'host-rollback'"));
            self::assertTrue($this->pdo->rollBack());
        } catch (\Throwable $throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $throwable;
        }

        self::assertSame('0', $this->countRows($independent, "SELECT COUNT(*) FROM maa_slug_scopes WHERE namespace = 'host-rollback'"));
        self::assertSame('0', $this->countRows($independent, "SELECT COUNT(*) FROM maa_slug_bindings WHERE entity_key = 'host-rollback'"));
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

    /** @return list<array<string, mixed>> */
    private function runFirstUseScopeRace(bool $conflicting): array
    {
        if (! function_exists('pcntl_fork')) {
            self::fail('WU-03 Scope concurrency evidence requires pcntl_fork.');
        }

        $readyFiles = [];
        $resultFiles = [];
        $startFile = tempnam(sys_get_temp_dir(), 'slug-wu03-scope-start-');
        self::assertNotFalse($startFile);
        unlink($startFile);
        for ($index = 0; $index < 2; $index++) {
            $readyFiles[$index] = tempnam(sys_get_temp_dir(), 'slug-wu03-scope-ready-');
            $resultFiles[$index] = tempnam(sys_get_temp_dir(), 'slug-wu03-scope-result-');
            self::assertNotFalse($readyFiles[$index]);
            self::assertNotFalse($resultFiles[$index]);
            unlink($readyFiles[$index]);
            unlink($resultFiles[$index]);
        }

        $children = [];
        for ($index = 0; $index < 2; $index++) {
            $childPid = pcntl_fork();
            self::assertNotSame(-1, $childPid);
            if ($childPid === 0) {
                try {
                    $pdo = $this->newTestConnection();
                    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
                    file_put_contents($readyFiles[$index], 'ready');
                    for ($attempt = 0; $attempt < 1000 && ! is_file($startFile); $attempt++) {
                        usleep(10000);
                    }
                    if (! is_file($startFile)) {
                        throw new \RuntimeException('Scope race barrier was not released.');
                    }

                    $profileKey = $conflicting && $index === 1 ? 'custom-v1' : 'ascii-v1';
                    $profiles = new SlugProfileRegistry();
                    $profiles->register(new StubSlugProfile(new SlugProfileKey($profileKey)));
                    $repository = new PdoScopeRepository($pdo, $profiles, $this->clock(), new PdoCapabilityGuard($pdo));
                    $scope = $repository->ensureScope(new ScopeProfileRequestDTO(
                        new SlugScope($conflicting ? 'race-conflicting-profile' : 'race-same-profile', null, null),
                        new SlugProfileKey($profileKey),
                    ));
                    file_put_contents($resultFiles[$index], json_encode([
                        'ok' => true,
                        'id' => $scope->id,
                        'profile' => $scope->profileKey->value,
                    ], JSON_THROW_ON_ERROR));
                } catch (\Throwable $throwable) {
                    file_put_contents($resultFiles[$index], json_encode(['class' => $throwable::class], JSON_THROW_ON_ERROR));
                }
                exit(0);
            }
            $children[] = $childPid;
        }

        $this->pdo = $this->newTestConnection();
        try {
            for ($attempt = 0; $attempt < 500; $attempt++) {
                if (is_file($readyFiles[0]) && is_file($readyFiles[1])) {
                    break;
                }
                usleep(10000);
            }
            self::assertFileExists($readyFiles[0]);
            self::assertFileExists($readyFiles[1]);
            file_put_contents($startFile, 'go');
            foreach ($children as $childPid) {
                pcntl_waitpid($childPid, $childStatus);
            }
            $results = [];
            foreach ($resultFiles as $resultFile) {
                self::assertFileExists($resultFile);
                $results[] = $this->decodeRaceResult($resultFile);
            }
        } catch (\Throwable $throwable) {
            file_put_contents($startFile, 'abort');
            foreach ($children as $childPid) {
                pcntl_waitpid($childPid, $childStatus);
            }
            throw $throwable;
        } finally {
            foreach ([$startFile, ...$readyFiles, ...$resultFiles] as $file) {
                if (is_string($file) && is_file($file)) {
                    unlink($file);
                }
            }
        }

        return $results;
    }

    private function countRows(\PDO $pdo, string $sql): string
    {
        $statement = $pdo->query($sql);
        self::assertNotFalse($statement);

        return (string) $statement->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function decodeRaceResult(string $file): array
    {
        $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            self::fail('Scope race child result must be a JSON object.');
        }

        $result = [];
        foreach ($decoded as $key => $value) {
            if (! is_string($key)) {
                self::fail('Scope race child result keys must be strings.');
            }
            $result[$key] = $value;
        }

        return $result;
    }
}
