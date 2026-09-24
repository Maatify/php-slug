<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\System\Transfer;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Slug\Lifecycle\Command\AtomicTransferCommand;
use Maatify\Slug\Lifecycle\Contract\ReservedSlugPolicyInterface;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\DTO\TransferReplacementIntentDTO;
use Maatify\Slug\Lifecycle\Enum\ClaimIntentModeEnum;
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

$sourceKey = $argv[1] ?? '';
$transferredSlug = $argv[2] ?? '';
$replacementSlug = $argv[3] ?? '';
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
/** Fixed UTC clock makes the transfer race history deterministic. */
$clock = new class implements ClockInterface {
    /** Returns the stable timestamp used by the transfer service. */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01T00:00:00.123456Z');
    }

    /** Returns UTC for persisted transfer history. */
    public function getTimezone(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }
};
/** The race fixture intentionally leaves candidates unreserved. */
$policy = new class implements ReservedSlugPolicyInterface {
    /** Never reserves a slug; the database arbitrates ownership races. */
    public function isReserved(SlugScope $scope, Slug $slug): bool
    {
        return false;
    }
};
$service = SlugLifecycleServiceFactory::create($pdo, $profiles, $policy, $clock);
$identity = static fn(string $entityKey): BindingIdentityDTO => new BindingIdentityDTO(
    new ScopeProfileRequestDTO(new SlugScope('transfer-system', null, null), new SlugProfileKey('ascii-v1')),
    new EntityReference('product', $entityKey),
);

try {
    $result = $service->atomicTransfer(new AtomicTransferCommand(
        $identity($sourceKey),
        $identity('transfer-target'),
        $transferredSlug,
        new TransferReplacementIntentDTO(ClaimIntentModeEnum::EXACT, $replacementSlug),
        1,
        2,
        new AuditContextDTO(),
    ));
    echo "OK\t" . $result->transferredClaim->slug->value . "\t" . $result->targetRevision . "\n";
} catch (\Throwable $throwable) {
    echo "ERR\t" . $throwable::class . "\t" . str_replace(["\n", "\t"], ' ', $throwable->getMessage()) . "\n";
}
