<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\System\Transfer;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Slug\Command\AddAliasCommand;
use Maatify\Slug\Command\AssignExactCommand;
use Maatify\Slug\Command\AtomicTransferCommand;
use Maatify\Slug\Contract\ReservedSlugPolicyInterface;
use Maatify\Slug\DTO\AuditContextDTO;
use Maatify\Slug\DTO\BindingIdentityDTO;
use Maatify\Slug\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\DTO\TransferReplacementIntentDTO;
use Maatify\Slug\Enum\ClaimIntentModeEnum;
use Maatify\Slug\Identity\EntityReference;
use Maatify\Slug\Identity\Slug;
use Maatify\Slug\Identity\SlugProfileKey;
use Maatify\Slug\Infrastructure\Persistence\PDO\Connection\PdoCapabilityGuard;
use Maatify\Slug\Lifecycle\SlugLifecycleService;
use Maatify\Slug\Profile\Registry\SlugProfileRegistry;
use Maatify\Slug\Scope\Value\SlugScope;
use Maatify\Slug\Tests\Unit\Allocation\TestSlugProfile;
use PDO;

echo "READY\n";
fflush(STDOUT);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$mode = $argv[1] ?? '';
$sourceKey = $argv[2] ?? '';
$targetKey = $argv[3] ?? '';
$slug = $argv[4] ?? '';
$sourceRevision = (int) ($argv[5] ?? 0);
$targetRevision = (int) ($argv[6] ?? 0);
$replacementMode = $argv[7] ?? '';
$replacementValue = $argv[8] ?? '';
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
$policy = new class implements ReservedSlugPolicyInterface {
    public function isReserved(SlugScope $scope, Slug $slug): bool
    {
        return false;
    }
};
$service = new SlugLifecycleService($pdo, $profiles, $policy, $clock, new PdoCapabilityGuard($pdo));
$identity = static fn(string $entityKey): BindingIdentityDTO => new BindingIdentityDTO(
    new ScopeProfileRequestDTO(new SlugScope('transfer-concurrency', null, null), new SlugProfileKey('ascii-v1')),
    new EntityReference('product', $entityKey),
);

try {
    if ($mode === 'transfer' || $mode === 'current-transfer') {
        $replacement = $mode === 'current-transfer'
            ? new TransferReplacementIntentDTO(ClaimIntentModeEnum::from($replacementMode), $replacementValue)
            : null;
        $result = $service->atomicTransfer(new AtomicTransferCommand(
            $identity($sourceKey),
            $identity($targetKey),
            $slug,
            $replacement,
            $sourceRevision,
            $targetRevision,
            new AuditContextDTO(),
        ));
        echo "OK\tTRANSFER\t" . $result->targetRevision . "\n";
    } elseif ($mode === 'alias') {
        $result = $service->addAlias(new AddAliasCommand($identity($sourceKey), $slug, $sourceRevision, new AuditContextDTO()));
        echo "OK\tALIAS\t" . $result->revision . "\n";
    } elseif ($mode === 'claim') {
        $result = $service->assignExact(new AssignExactCommand($identity($sourceKey), $slug, $sourceRevision, new AuditContextDTO()));
        echo "OK\tCLAIM\t" . $result->revision . "\n";
    } else {
        throw new \InvalidArgumentException('Unknown concurrency worker mode.');
    }
} catch (\Throwable $throwable) {
    echo "ERR\t" . $throwable::class . "\t" . str_replace(["\n", "\t"], ' ', $throwable->getMessage()) . "\n";
}
