<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Integration\Persistence\Scope;

use Maatify\Slug\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Identity\EntityReference;
use Maatify\Slug\Identity\SlugProfileKey;
use Maatify\Slug\Infrastructure\Persistence\PDO\Connection\PdoCapabilityGuard;
use Maatify\Slug\Internal\Transaction\PdoTransactionCoordinator;
use Maatify\Slug\Infrastructure\Persistence\PDO\Scope\PdoScopeRepository;
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

        self::assertSame('catalog', $scope->scope->namespace);
        self::assertSame(1, $binding->id);
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
}
