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

final class BeginFailingPdo extends PDO
{
    public function __construct() {}

    public function inTransaction(): bool
    {
        return false;
    }

    public function beginTransaction(): bool
    {
        return false;
    }
}

final class CommitFailingPdo extends PDO
{
    public bool $active = false;
    public bool $rolledBack = false;

    public function __construct() {}

    public function inTransaction(): bool
    {
        return $this->active;
    }

    public function beginTransaction(): bool
    {
        $this->active = true;
        return true;
    }

    public function commit(): bool
    {
        return false;
    }

    public function rollBack(): bool
    {
        $this->active = false;
        $this->rolledBack = true;
        return true;
    }
}

final class SavepointFailingPdo extends PDO
{
    public function __construct() {}

    public function inTransaction(): bool
    {
        return true;
    }

    public function exec(string $statement): int|false
    {
        return false;
    }
}
