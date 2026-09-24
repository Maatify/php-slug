<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\System\Claim;

use Maatify\Slug\Lifecycle\Contract\ReservedSlugPolicyInterface;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\ValueObject\EntityReference;
use Maatify\Slug\Canonicalization\ValueObject\Slug;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Lifecycle\Repository\Pdo\Support\PdoCapabilityGuard;
use Maatify\Slug\Lifecycle\Repository\Pdo\Registry\PdoRegistryRepository;
use Maatify\Slug\Lifecycle\Repository\Pdo\Scope\PdoScopeRepository;
use Maatify\Slug\Lifecycle\Service\Ownership\RegistryClaimCoordinator;
use Maatify\Slug\Lifecycle\Repository\Pdo\Transaction\PdoTransactionCoordinator;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistry;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;
use Maatify\Slug\Tests\Unit\Allocation\TestSlugProfile;
use Maatify\SharedCommon\Contracts\ClockInterface;
use DateTimeImmutable;
use DateTimeZone;

echo "READY\n";
fflush(STDOUT);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$namespace = $argv[1] ?? '';
$entityKey = $argv[2] ?? '';
$mode = $argv[3] ?? '';

if (fgets(STDIN) === false) {
    exit(2);
}

$pdo = new \PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        (string) getenv('SLUG_TEST_DB_HOST'),
        (int) getenv('SLUG_TEST_DB_PORT'),
        (string) getenv('SLUG_TEST_DB_NAME'),
    ),
    (string) getenv('SLUG_TEST_DB_USER'),
    (string) getenv('SLUG_TEST_DB_PASSWORD'),
    [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false],
);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_bin');
$profiles = new SlugProfileRegistry();
$profiles->register(new TestSlugProfile());
/** Fixed UTC clock keeps competing claim attempts comparable across worker processes. */
$clock = new class implements ClockInterface {
    /** Returns the deterministic timestamp used by the race fixture. */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01T00:00:00.123456Z');
    }

    /** Returns UTC, matching the persistence timestamp contract. */
    public function getTimezone(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }
};
$capabilities = new PdoCapabilityGuard($pdo);
$scopes = new PdoScopeRepository($pdo, $profiles, $clock, $capabilities);
$repository = new PdoRegistryRepository($pdo, $profiles, $scopes, $clock, $capabilities);
/** The race fixture intentionally leaves every candidate available to contention. */
$policy = new class implements ReservedSlugPolicyInterface {
    /** Never reserves a slug so uniqueness is decided by the real registry constraints. */
    public function isReserved(SlugScope $scope, Slug $slug): bool
    {
        return false;
    }
};
$coordinator = new RegistryClaimCoordinator($repository, $profiles, $policy, new PdoTransactionCoordinator($pdo));
$identity = new BindingIdentityDTO(
    new ScopeProfileRequestDTO(new SlugScope($namespace, null, null), new SlugProfileKey('ascii-v1')),
    new EntityReference('product', $entityKey),
);

try {
    $result = $mode === 'generated'
        ? $coordinator->allocateGenerated($identity, 'Product', 0)
        : $coordinator->claimExact($identity, 'race-slug', 0);
    echo "OK\t" . $result->claim->slug->value . "\n";
} catch (\Throwable $throwable) {
    echo "ERR\t" . $throwable::class . "\t" . str_replace(["\n", "\t"], ' ', $throwable->getMessage()) . "\n";
}
