<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\System\Lifecycle;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Slug\Lifecycle\Command\AddAliasCommand;
use Maatify\Slug\Lifecycle\Command\ChangeExactCommand;
use Maatify\Slug\Lifecycle\Command\ChangeGeneratedCommand;
use Maatify\Slug\Lifecycle\Contract\ReservedSlugPolicyInterface;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\ValueObject\EntityReference;
use Maatify\Slug\Canonicalization\ValueObject\Slug;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Lifecycle\Service\SlugLifecycleService;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistry;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;
use Maatify\Slug\Tests\Support\SlugLifecycleServiceFactory;
use Maatify\Slug\Tests\Unit\Allocation\TestSlugProfile;
use PDO;

echo "READY\n";
fflush(STDOUT);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$entityKey = $argv[1] ?? '';
$slug = $argv[2] ?? '';
$mode = $argv[3] ?? 'change';
$isolation = $argv[4] ?? '';
if (fgets(STDIN) === false) {
    exit(2);
}

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        (string) getenv('SLUG_TEST_DB_HOST'),
        (int) getenv('SLUG_TEST_DB_PORT'),
        (string) getenv('SLUG_TEST_DB_NAME'),
    ),
    (string) getenv('SLUG_TEST_DB_USER'),
    (string) getenv('SLUG_TEST_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_bin');
if ($isolation === 'READ COMMITTED') {
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
}
$profiles = new SlugProfileRegistry();
$profiles->register(new TestSlugProfile());
/** Fixed UTC clock makes concurrent lifecycle history deterministic. */
$clock = new class implements ClockInterface {
    /** Returns the timestamp shared by both race participants. */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01T00:00:00.123456Z');
    }

    /** Returns UTC for the lifecycle persistence contract. */
    public function getTimezone(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }
};
/** The worker's reservation policy is permissive so database contention is observable. */
$policy = new class implements ReservedSlugPolicyInterface {
    /** Never reserves a candidate in this race scenario. */
    public function isReserved(SlugScope $scope, Slug $slug): bool
    {
        return false;
    }
};
$service = SlugLifecycleServiceFactory::create($pdo, $profiles, $policy, $clock);
$identity = new BindingIdentityDTO(
    new ScopeProfileRequestDTO(new SlugScope('lifecycle-system', null, null), new SlugProfileKey('ascii-v1')),
    new EntityReference('product', $entityKey),
);

try {
    if ($mode === 'alias') {
        $result = $service->addAlias(new AddAliasCommand($identity, $slug, 1, new AuditContextDTO()));
        echo "OK\tALIAS\t" . $result->revision . "\n";
    } elseif ($mode === 'generated') {
        $result = $service->changeGenerated(new ChangeGeneratedCommand($identity, $slug, 1, new AuditContextDTO()));
        if ($result->currentSlug === null) {
            throw new \RuntimeException('Lifecycle generated change returned no current slug.');
        }
        echo "OK\t" . $result->currentSlug->value . "\t" . $result->revision . "\n";
    } else {
        $result = $service->changeExact(new ChangeExactCommand($identity, $slug, 1, new AuditContextDTO()));
        if ($result->currentSlug === null) {
            throw new \RuntimeException('Lifecycle change returned no current slug.');
        }
        echo "OK\t" . $result->currentSlug->value . "\t" . $result->revision . "\n";
    }
} catch (\Throwable $throwable) {
    echo "ERR\t" . $throwable::class . "\t" . str_replace(["\n", "\t"], ' ', $throwable->getMessage()) . "\n";
}
