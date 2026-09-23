<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Persistence\Connection;

use Maatify\Slug\Canonicalization\Exception\SlugRuntimeCompatibilityException;
use Maatify\Slug\Lifecycle\Repository\Pdo\Support\PdoCapabilityGuard;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class PdoCapabilityGuardTest extends TestCase
{
    public function testRollbackToSavepointFailureIsNotSwallowed(): void
    {
        $guard = new PdoCapabilityGuard(new CapabilityProbeRollbackFailurePdo());
        $cleanup = new ReflectionMethod(PdoCapabilityGuard::class, 'cleanupProbeTransaction');

        $this->expectException(SlugRuntimeCompatibilityException::class);
        $cleanup->invoke($guard, true, false, 'maa_slug_cap_test');
    }

    public function testSavepointReleaseFailureIsNotSwallowed(): void
    {
        $guard = new PdoCapabilityGuard(new CapabilityProbeReleaseFailurePdo());
        $cleanup = new ReflectionMethod(PdoCapabilityGuard::class, 'cleanupProbeTransaction');

        $this->expectException(SlugRuntimeCompatibilityException::class);
        $cleanup->invoke($guard, true, false, 'maa_slug_cap_test');
    }

    public function testOwnedProbeRollbackFailureIsNotSwallowed(): void
    {
        $guard = new PdoCapabilityGuard(new CapabilityProbeTransactionRollbackFailurePdo());
        $cleanup = new ReflectionMethod(PdoCapabilityGuard::class, 'cleanupProbeTransaction');

        $this->expectException(SlugRuntimeCompatibilityException::class);
        $cleanup->invoke($guard, false, true, 'maa_slug_cap_test');
    }
}

/** PDO double that fails only the rollback-to-savepoint cleanup operation. */
final class CapabilityProbeRollbackFailurePdo extends PDO
{
    /** Keeps the PDO parent unconstructed because the probe methods are fully controlled. */
    public function __construct() {}

    /** Reports an active probe transaction so cleanup reaches the injected failure. */
    public function inTransaction(): bool
    {
        return true;
    }

    /** Returns false for rollback-to-savepoint and succeeds for unrelated probe SQL. */
    public function exec(string $statement): int|false
    {
        return str_starts_with($statement, 'ROLLBACK TO SAVEPOINT') ? false : 1;
    }
}

/** PDO double that fails only release of the capability probe savepoint. */
final class CapabilityProbeReleaseFailurePdo extends PDO
{
    /** Keeps the PDO parent unconstructed because the probe methods are fully controlled. */
    public function __construct() {}

    /** Reports an active probe transaction so cleanup reaches the injected failure. */
    public function inTransaction(): bool
    {
        return true;
    }

    /** Returns false for savepoint release and succeeds for unrelated probe SQL. */
    public function exec(string $statement): int|false
    {
        return str_starts_with($statement, 'RELEASE SAVEPOINT') ? false : 1;
    }
}

/** PDO double that fails the rollback used when the capability probe owns the transaction. */
final class CapabilityProbeTransactionRollbackFailurePdo extends PDO
{
    /** Keeps the PDO parent unconstructed because only the cleanup boundary is under test. */
    public function __construct() {}

    /** Reports an active transaction so the guard attempts the injected rollback. */
    public function inTransaction(): bool
    {
        return true;
    }

    /** Returns false to prove the guard converts owned-transaction cleanup failure. */
    public function rollBack(): bool
    {
        return false;
    }
}
