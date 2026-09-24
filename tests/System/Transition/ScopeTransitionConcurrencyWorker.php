<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\System\Transition;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Slug\Lifecycle\Command\TransitionScopeCommand;
use Maatify\Slug\Lifecycle\Contract\ReservedSlugPolicyInterface;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeTransitionClaimIntentDTO;
use Maatify\Slug\Factory\SlugEngineFactory;
use Maatify\Slug\Lifecycle\Enum\ClaimIntentModeEnum;
use Maatify\Slug\Lifecycle\Enum\ScopeTransitionModeEnum;
use Maatify\Slug\Lifecycle\ValueObject\EntityReference;
use Maatify\Slug\Canonicalization\ValueObject\Slug;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistry;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;
use Maatify\Slug\Tests\Unit\Allocation\TestReservedSlugPolicy;
use Maatify\Slug\Tests\Unit\Allocation\TestSlugProfile;
use PDO;

echo "READY\n";
fflush(STDOUT);
require dirname(__DIR__, 3) . '/vendor/autoload.php';

$sourceNamespace = $argv[1] ?? '';
$targetNamespace = $argv[2] ?? '';
$entityKey = $argv[3] ?? '';
$intentMode = $argv[4] ?? 'exact';
$intentValue = $argv[5] ?? '';
$sourceRevision = (int) ($argv[6] ?? 1);
$idempotencyKey = $argv[7] ?? '';
if (fgets(STDIN) === false) {
    exit(2);
}

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', (string) getenv('SLUG_TEST_DB_HOST'), (int) getenv('SLUG_TEST_DB_PORT'), (string) getenv('SLUG_TEST_DB_NAME')),
    (string) getenv('SLUG_TEST_DB_USER'),
    (string) getenv('SLUG_TEST_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_bin');
$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
$profiles = new SlugProfileRegistry();
$profiles->register(new TestSlugProfile());
/** Fixed UTC clock makes concurrent transition history deterministic. */
$clock = new class implements ClockInterface {
    /** Returns the timestamp shared by transition workers. */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01T00:00:00.123456Z');
    }

    /** Returns UTC for persisted transition history. */
    public function getTimezone(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }
};
$engine = SlugEngineFactory::create($pdo, $profiles, new TestReservedSlugPolicy(), $clock);
$source = new BindingIdentityDTO(new ScopeProfileRequestDTO(new SlugScope($sourceNamespace, null, null), new SlugProfileKey('ascii-v1')), new EntityReference('product', $entityKey));
$target = new ScopeProfileRequestDTO(new SlugScope($targetNamespace, null, null), new SlugProfileKey('ascii-v1'));
$mode = $intentMode === 'generated' ? ClaimIntentModeEnum::GENERATED : ClaimIntentModeEnum::EXACT;
$audit = new AuditContextDTO(idempotencyKey: $idempotencyKey === '' ? null : $idempotencyKey);

try {
    $result = $engine->transitionScope(new TransitionScopeCommand(
        $source,
        $target,
        ScopeTransitionModeEnum::MOVE,
        new ScopeTransitionClaimIntentDTO($mode, $intentValue),
        $sourceRevision,
        $audit,
    ));
    if ($result->targetClaim === null) {
        throw new \RuntimeException('Transition worker returned no target claim.');
    }
    echo "OK\t" . $result->targetClaim->value . "\t" . ($result->replayed ? '1' : '0') . "\n";
} catch (\Throwable $throwable) {
    echo "ERR\t" . $throwable::class . "\t" . str_replace(["\n", "\t"], ' ', $throwable->getMessage()) . "\n";
}
