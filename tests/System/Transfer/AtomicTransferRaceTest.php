<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\System\Transfer;

use Maatify\Slug\Lifecycle\Command\AssignExactCommand;
use Maatify\Slug\Lifecycle\Command\AtomicTransferCommand;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Registry\DTO\BindingIdentityDTO;
use Maatify\Slug\Scope\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Exception\SlugRevisionConflictException;
use Maatify\Slug\Registry\Value\EntityReference;
use Maatify\Slug\Profile\Value\SlugProfileKey;
use Maatify\Slug\Persistence\PDO\Connection\PdoCapabilityGuard;
use Maatify\Slug\Lifecycle\SlugLifecycleService;
use Maatify\Slug\Profile\Registry\SlugProfileRegistry;
use Maatify\Slug\Scope\Value\SlugScope;
use Maatify\Slug\Tests\Integration\Schema\MySqlIntegrationTestCase;
use Maatify\Slug\Tests\Unit\Allocation\TestReservedSlugPolicy;
use Maatify\Slug\Tests\Unit\Allocation\TestSlugProfile;

final class AtomicTransferRaceTest extends MySqlIntegrationTestCase
{
    public function testConcurrentCurrentTransfersSerializeOnTheTargetBinding(): void
    {
        if (! function_exists('proc_open')) {
            self::fail('WU-05 real transfer concurrency evidence requires proc_open.');
        }

        $service = $this->service();
        $sourceA = $this->identity('transfer-source-a');
        $sourceB = $this->identity('transfer-source-b');
        $target = $this->identity('transfer-target');
        $service->assignExact(new AssignExactCommand($sourceA, 'transfer-a', null, new AuditContextDTO()));
        $service->assignExact(new AssignExactCommand($sourceB, 'transfer-b', null, new AuditContextDTO()));
        $service->assignExact(new AssignExactCommand($target, 'target-current', null, new AuditContextDTO()));
        $service->releaseAllOwnership(new \Maatify\Slug\Lifecycle\Command\ReleaseAllOwnershipCommand($target, 1, new AuditContextDTO()));

        $workers = [];
        foreach ([['transfer-source-a', 'transfer-a', 'replacement-a'], ['transfer-source-b', 'transfer-b', 'replacement-b']] as $arguments) {
            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = proc_open(
                [PHP_BINARY, __DIR__ . '/AtomicTransferRaceWorker.php', ...$arguments],
                $descriptors,
                $pipes,
                dirname(__DIR__, 3),
            );
            if (! is_resource($process)) {
                self::fail('Unable to start transfer race worker.');
            }
            $workers[] = [$process, $pipes];
        }

        foreach ($workers as [$process, $pipes]) {
            self::assertSame("READY\n", fgets($pipes[1]));
        }
        foreach ($workers as [$process, $pipes]) {
            fwrite($pipes[0], "GO\n");
            fclose($pipes[0]);
        }

        $results = [];
        foreach ($workers as [$process, $pipes]) {
            $line = fgets($pipes[1]);
            self::assertIsString($line);
            $parts = explode("\t", trim($line));
            $results[] = ['status' => $parts[0], 'value' => $parts[1] ?? '', 'class' => $parts[1] ?? ''];
            stream_set_blocking($pipes[2], false);
            $diagnostic = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), trim((string) $diagnostic));
        }

        self::assertCount(1, array_filter($results, static fn(array $result): bool => $result['status'] === 'OK'));
        $failures = array_values(array_filter($results, static fn(array $result): bool => $result['status'] === 'ERR'));
        self::assertCount(1, $failures);
        self::assertSame(SlugRevisionConflictException::class, $failures[0]['class']);
        self::assertSame(3, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'transfer-target']));
        self::assertSame(3, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry'));
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE slug IN (:first, :second)', ['first' => 'replacement-a', 'second' => 'replacement-b']));
    }

    private function service(): SlugLifecycleService
    {
        $profiles = new SlugProfileRegistry();
        $profiles->register(new TestSlugProfile());
        return new SlugLifecycleService($this->pdo, $profiles, new TestReservedSlugPolicy(), $this->clock(), new PdoCapabilityGuard($this->pdo));
    }

    private function identity(string $entityKey): BindingIdentityDTO
    {
        return new BindingIdentityDTO(
            new ScopeProfileRequestDTO(new SlugScope('transfer-system', null, null), new SlugProfileKey('ascii-v1')),
            new EntityReference('product', $entityKey),
        );
    }

    /** @param array<string, int|string> $parameters */
    private function scalarInt(string $sql, array $parameters = []): int
    {
        $statement = $this->pdo->prepare($sql);
        self::assertNotFalse($statement);
        $statement->execute($parameters);
        return (int) $statement->fetchColumn();
    }
}
