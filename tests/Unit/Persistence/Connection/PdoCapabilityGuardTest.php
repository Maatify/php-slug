<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Persistence\Connection;

use Maatify\Slug\Exception\SlugRuntimeCompatibilityException;
use Maatify\Slug\Persistence\PDO\Connection\PdoCapabilityGuard;
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

final class CapabilityProbeRollbackFailurePdo extends PDO
{
    public function __construct() {}

    public function inTransaction(): bool
    {
        return true;
    }

    public function exec(string $statement): int|false
    {
        return str_starts_with($statement, 'ROLLBACK TO SAVEPOINT') ? false : 1;
    }
}

final class CapabilityProbeReleaseFailurePdo extends PDO
{
    public function __construct() {}

    public function inTransaction(): bool
    {
        return true;
    }

    public function exec(string $statement): int|false
    {
        return str_starts_with($statement, 'RELEASE SAVEPOINT') ? false : 1;
    }
}

final class CapabilityProbeTransactionRollbackFailurePdo extends PDO
{
    public function __construct() {}

    public function inTransaction(): bool
    {
        return true;
    }

    public function rollBack(): bool
    {
        return false;
    }
}
