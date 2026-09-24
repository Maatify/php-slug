<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\System\Concurrency;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Slug\Lifecycle\Command\AtomicTransferCommand;
use Maatify\Slug\Lifecycle\Command\ChangeExactCommand;
use Maatify\Slug\Lifecycle\Command\TransitionScopeCommand;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeTransitionClaimIntentDTO;
use Maatify\Slug\Factory\SlugEngineFactory;
use Maatify\Slug\Lifecycle\Enum\ClaimIntentModeEnum;
use Maatify\Slug\Lifecycle\Enum\ScopeTransitionModeEnum;
use Maatify\Slug\Lifecycle\ValueObject\EntityReference;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistry;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;
use Maatify\Slug\Tests\Unit\Allocation\TestReservedSlugPolicy;
use Maatify\Slug\Tests\Unit\Allocation\TestSlugProfile;
use PDO;

echo "READY\n";
fflush(STDOUT);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$mode = $argv[1] ?? '';
$sourceNamespace = $argv[2] ?? '';
$sourceEntity = $argv[3] ?? '';
$targetNamespace = $argv[4] ?? '';
$targetEntity = $argv[5] ?? '';
$value = $argv[6] ?? '';
$sourceRevision = (int) ($argv[7] ?? 0);
$targetRevision = (int) ($argv[8] ?? 0);
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
$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
$profiles = new SlugProfileRegistry();
$profiles->register(new TestSlugProfile());
/** Fixed UTC clock removes timestamp variance from concurrent mutation outcomes. */
$clock = new class implements ClockInterface {
    /** Returns the deterministic timestamp used in generated history rows. */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01T00:00:00.123456Z');
    }

    /** Returns UTC for the persisted history timestamp contract. */
    public function getTimezone(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }
};
$engine = SlugEngineFactory::create($pdo, $profiles, new TestReservedSlugPolicy(), $clock);
$identity = static fn(string $namespace, string $entity): BindingIdentityDTO => new BindingIdentityDTO(
    new ScopeProfileRequestDTO(new SlugScope($namespace, null, null), new SlugProfileKey('ascii-v1')),
    new EntityReference('product', $entity),
);

try {
    if ($mode === 'transfer') {
        $engine->atomicTransfer(new AtomicTransferCommand(
            $identity($sourceNamespace, $sourceEntity),
            $identity($targetNamespace, $targetEntity),
            $value,
            null,
            $sourceRevision,
            $targetRevision,
            new AuditContextDTO(),
        ));
        echo "OK\tTRANSFER\n";
    } elseif ($mode === 'change') {
        $engine->changeExact(new ChangeExactCommand(
            $identity($sourceNamespace, $sourceEntity),
            $value,
            $sourceRevision,
            new AuditContextDTO(),
        ));
        echo "OK\tCHANGE\n";
    } elseif ($mode === 'transition') {
        $engine->transitionScope(new TransitionScopeCommand(
            $identity($sourceNamespace, $sourceEntity),
            new ScopeProfileRequestDTO(new SlugScope($targetNamespace, null, null), new SlugProfileKey('ascii-v1')),
            ScopeTransitionModeEnum::MOVE,
            new ScopeTransitionClaimIntentDTO(ClaimIntentModeEnum::EXACT, $value),
            $sourceRevision,
            new AuditContextDTO(),
        ));
        echo "OK\tTRANSITION\n";
    } else {
        throw new \InvalidArgumentException('Unknown WU-07 concurrency worker mode.');
    }
} catch (\Throwable $throwable) {
    echo "ERR\t" . $throwable::class . "\t" . str_replace(["\n", "\t"], ' ', $throwable->getMessage()) . "\n";
}
