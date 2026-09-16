<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Integration\Adoption;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Slug\Command\AddAliasCommand;
use Maatify\Slug\Command\AdoptAliasCommand;
use Maatify\Slug\Command\AdoptCurrentCommand;
use Maatify\Slug\Command\AdoptHistoricalCommand;
use Maatify\Slug\Contract\ReservedSlugPolicyInterface;
use Maatify\Slug\DTO\AuditContextDTO;
use Maatify\Slug\DTO\BindingIdentityDTO;
use Maatify\Slug\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Engine\SlugEngineFactory;
use Maatify\Slug\Identity\EntityReference;
use Maatify\Slug\Identity\Slug;
use Maatify\Slug\Identity\SlugProfileKey;
use Maatify\Slug\Profile\Registry\SlugProfileRegistry;
use Maatify\Slug\Scope\Value\SlugScope;
use Maatify\Slug\Tests\Unit\Allocation\TestReservedSlugPolicy;
use Maatify\Slug\Tests\Unit\Allocation\TestSlugProfile;
use PDO;

echo "READY\n";
fflush(STDOUT);
require dirname(__DIR__, 3) . '/vendor/autoload.php';

$mode = $argv[1] ?? '';
$namespace = $argv[2] ?? '';
$entityKey = $argv[3] ?? '';
$candidate = $argv[4] ?? '';
$expectedRevision = ($argv[5] ?? '') === 'null' ? null : (int) ($argv[5] ?? 0);
$idempotencyKey = $argv[6] ?? '';
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
$engine = SlugEngineFactory::create($pdo, $profiles, new TestReservedSlugPolicy(), $clock);
$identity = new BindingIdentityDTO(
    new ScopeProfileRequestDTO(new SlugScope($namespace, null, null), new SlugProfileKey('ascii-v1')),
    new EntityReference('product', $entityKey),
);
$audit = new AuditContextDTO(idempotencyKey: $idempotencyKey === '' ? null : $idempotencyKey);

try {
    $result = match ($mode) {
        'current' => $engine->adoptCurrent(new AdoptCurrentCommand($identity, $candidate, null, $expectedRevision, $audit)),
        'historical' => $engine->adoptHistorical(new AdoptHistoricalCommand($identity, $candidate, null, (int) $expectedRevision, $audit)),
        'alias' => $engine->adoptAlias(new AdoptAliasCommand($identity, $candidate, null, (int) $expectedRevision, $audit)),
        'writer' => $engine->addAlias(new AddAliasCommand($identity, $candidate, (int) $expectedRevision, $audit)),
        default => throw new \InvalidArgumentException('Unknown adoption concurrency worker mode.'),
    };
    $role = property_exists($result, 'adoptedClaim') ? $result->adoptedClaim->role->value : 'ACTIVE_ALIAS';
    $replayed = property_exists($result, 'replayed') && $result->replayed ? '1' : '0';
    echo "OK\t" . $role . "\t" . $replayed . "\n";
} catch (\Throwable $throwable) {
    echo "ERR\t" . $throwable::class . "\t" . str_replace(["\n", "\t"], ' ', $throwable->getMessage()) . "\n";
}
