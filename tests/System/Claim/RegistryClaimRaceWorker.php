<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\System\Claim;

use Maatify\Slug\Lifecycle\Contract\ReservedSlugPolicyInterface;
use Maatify\Slug\Registry\DTO\BindingIdentityDTO;
use Maatify\Slug\Scope\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Registry\Value\EntityReference;
use Maatify\Slug\Text\Value\Slug;
use Maatify\Slug\Profile\Value\SlugProfileKey;
use Maatify\Slug\Persistence\PDO\Connection\PdoCapabilityGuard;
use Maatify\Slug\Persistence\PDO\Registry\PdoRegistryRepository;
use Maatify\Slug\Persistence\PDO\Scope\PdoScopeRepository;
use Maatify\Slug\Registry\Internal\Claim\RegistryClaimCoordinator;
use Maatify\Slug\Persistence\Transaction\PdoTransactionCoordinator;
use Maatify\Slug\Profile\Registry\SlugProfileRegistry;
use Maatify\Slug\Scope\Value\SlugScope;
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
$clock = new class implements ClockInterface {
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01T00:00:00.123456Z');
    }

    public function getTimezone(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }
};
$capabilities = new PdoCapabilityGuard($pdo);
$scopes = new PdoScopeRepository($pdo, $profiles, $clock, $capabilities);
$repository = new PdoRegistryRepository($pdo, $profiles, $scopes, $clock, $capabilities);
$policy = new class implements ReservedSlugPolicyInterface {
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
