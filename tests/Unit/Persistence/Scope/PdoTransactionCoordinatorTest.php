<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Persistence\Scope;

use Maatify\Slug\Lifecycle\Repository\Pdo\Transaction\PdoTransactionCoordinator;
use Maatify\Slug\Lifecycle\Exception\SlugTransactionParticipationException;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PdoTransactionCoordinatorTest extends TestCase
{
    public function testGeneratedSavepointNamesUseTheClosedPackageFormat(): void
    {
        /** @var PDO $pdo */
        $pdo = (new ReflectionClass(PDO::class))->newInstanceWithoutConstructor();
        $coordinator = new PdoTransactionCoordinator($pdo);

        for ($index = 0; $index < 16; $index++) {
            self::assertMatchesRegularExpression('/\Amaa_slug_sp_[A-F0-9]{32}\z/', $coordinator->newSavepointName());
        }
    }

    public function testBeginFailureIsReportedBeforeCallbackMutation(): void
    {
        $pdo = new BeginFailingPdo();
        $callbackCalled = false;

        try {
            (new PdoTransactionCoordinator($pdo))->run(function () use (&$callbackCalled): void {
                $callbackCalled = true;
            });
            self::fail('Expected transaction establishment failure.');
        } catch (SlugTransactionParticipationException $exception) {
            self::assertStringContainsString('begin', strtolower($exception->getMessage()));
        }

        self::assertFalse($callbackCalled);
    }

    public function testCommitFailureIsReportedAfterCallbackAndRollbackIsAttempted(): void
    {
        $pdo = new CommitFailingPdo();
        $called = false;

        $this->expectException(SlugTransactionParticipationException::class);
        try {
            (new PdoTransactionCoordinator($pdo))->run(function () use (&$called): void {
                $called = true;
            });
        } finally {
            self::assertTrue($called);
            self::assertTrue($pdo->rolledBack);
        }
    }

    public function testSavepointFailureIsReportedBeforeCallbackMutation(): void
    {
        $pdo = new SavepointFailingPdo();
        $callbackCalled = false;

        try {
            (new PdoTransactionCoordinator($pdo))->run(function () use (&$callbackCalled): void {
                $callbackCalled = true;
            });
            self::fail('Expected savepoint establishment failure.');
        } catch (SlugTransactionParticipationException $exception) {
            self::assertStringContainsString('savepoint', strtolower($exception->getMessage()));
        }

        self::assertFalse($callbackCalled);
    }
}

/** PDO double whose first transaction boundary cannot be established. */
final class BeginFailingPdo extends PDO
{
    /** Avoids opening a real connection because transaction behavior is injected. */
    public function __construct() {}

    /** Models a connection outside a transaction before begin failure. */
    public function inTransaction(): bool
    {
        return false;
    }

    /** Rejects transaction start so the callback must not run. */
    public function beginTransaction(): bool
    {
        return false;
    }
}

/** PDO double that accepts begin but rejects commit and records rollback cleanup. */
final class CommitFailingPdo extends PDO
{
    /** Tracks the synthetic transaction state used by the coordinator test. */
    public bool $active = false;

    /** Proves the coordinator attempted rollback after commit failure. */
    public bool $rolledBack = false;

    /** Avoids opening a real connection because transaction behavior is injected. */
    public function __construct() {}

    /** Reports the synthetic transaction state. */
    public function inTransaction(): bool
    {
        return $this->active;
    }

    /** Starts the synthetic transaction accepted by the coordinator. */
    public function beginTransaction(): bool
    {
        $this->active = true;
        return true;
    }

    /** Rejects commit to exercise coordinator error translation and cleanup. */
    public function commit(): bool
    {
        return false;
    }

    /** Clears the synthetic transaction and records that rollback was attempted. */
    public function rollBack(): bool
    {
        $this->active = false;
        $this->rolledBack = true;
        return true;
    }
}

/** PDO double that reports every savepoint statement as failed. */
final class SavepointFailingPdo extends PDO
{
    /** Avoids opening a real connection because savepoint behavior is injected. */
    public function __construct() {}

    /** Reports an outer transaction so the coordinator chooses savepoint mode. */
    public function inTransaction(): bool
    {
        return true;
    }

    /** Rejects savepoint creation before the callback can mutate state. */
    public function exec(string $statement): int|false
    {
        return false;
    }
}
