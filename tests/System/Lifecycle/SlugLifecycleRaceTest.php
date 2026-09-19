<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\System\Lifecycle;

use Maatify\Slug\Lifecycle\Command\AddAliasCommand;
use Maatify\Slug\Lifecycle\Command\AssignExactCommand;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\Exception\SlugAlreadyClaimedException;
use Maatify\Slug\Lifecycle\Exception\SlugRevisionConflictException;
use Maatify\Slug\Lifecycle\ValueObject\EntityReference;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Lifecycle\Service\SlugLifecycleService;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistry;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;
use Maatify\Slug\Tests\Support\SlugLifecycleServiceFactory;
use Maatify\Slug\Tests\Integration\Schema\MySqlIntegrationTestCase;
use Maatify\Slug\Tests\Unit\Allocation\TestReservedSlugPolicy;
use Maatify\Slug\Tests\Unit\Allocation\TestSlugProfile;

final class SlugLifecycleRaceTest extends MySqlIntegrationTestCase
{
    public function testConcurrentCasChangesHaveOneCommitAndOneRevisionConflict(): void
    {
        if (! function_exists('proc_open')) {
            self::fail('WU-05 real lifecycle concurrency evidence requires proc_open.');
        }

        $service = $this->service();
        $identity = $this->identity('cas-race');
        $service->assignExact(new AssignExactCommand($identity, 'initial', null, new AuditContextDTO()));

        $workers = [];
        foreach ([['cas-race', 'first-change'], ['cas-race', 'second-change']] as [$entityKey, $slug]) {
            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = proc_open(
                [PHP_BINARY, __DIR__ . '/SlugLifecycleRaceWorker.php', $entityKey, $slug],
                $descriptors,
                $pipes,
                dirname(__DIR__, 3),
            );
            if (! is_resource($process)) {
                self::fail('Unable to start lifecycle race worker.');
            }
            $workers[] = [$process, $pipes];
        }

        foreach ($workers as [$process, $pipes]) {
            $ready = fgets($pipes[1]);
            self::assertSame("READY\n", $ready);
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
        self::assertSame(2, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'cas-race']));
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_history'));
    }

    public function testCrossBindingExactChangesDoNotInvertRetainedAndCandidateClaims(): void
    {
        self::assertTrue(function_exists('proc_open'), 'WU-05 real lifecycle concurrency evidence requires proc_open.');
        $service = $this->service();
        $service->assignExact(new AssignExactCommand($this->identity('cross-exact-a'), 'z', null, new AuditContextDTO()));
        $service->assignExact(new AssignExactCommand($this->identity('cross-exact-b'), 'a', null, new AuditContextDTO()));

        $results = $this->runWorkers([
            ['cross-exact-a', 'a', 'change', 'READ COMMITTED'],
            ['cross-exact-b', 'z', 'change', 'READ COMMITTED'],
        ]);

        self::assertCount(2, $results);
        foreach ($results as $result) {
            self::assertSame('ERR', $result['status']);
            self::assertSame(SlugAlreadyClaimedException::class, $result['class']);
        }
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry'));
        self::assertSame(1, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'cross-exact-a']));
        self::assertSame(1, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'cross-exact-b']));
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_history'));
    }

    public function testConcurrentExactChangesLeaveNoFailedCandidateHistory(): void
    {
        self::assertTrue(function_exists('proc_open'), 'WU-05 real lifecycle concurrency evidence requires proc_open.');
        $service = $this->service();
        $service->assignExact(new AssignExactCommand($this->identity('exact-race-a'), 'exact-old-a', null, new AuditContextDTO()));
        $service->assignExact(new AssignExactCommand($this->identity('exact-race-b'), 'exact-old-b', null, new AuditContextDTO()));

        $results = $this->runWorkers([
            ['exact-race-a', 'exact-race-target', 'change', 'READ COMMITTED'],
            ['exact-race-b', 'exact-race-target', 'change', 'READ COMMITTED'],
        ]);

        self::assertCount(1, array_filter($results, static fn(array $result): bool => $result['status'] === 'OK'));
        $failures = array_values(array_filter($results, static fn(array $result): bool => $result['status'] === 'ERR'));
        self::assertCount(1, $failures);
        self::assertSame(SlugAlreadyClaimedException::class, $failures[0]['class']);
        self::assertSame(3, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry'));
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE slug = :slug AND claim_role = :role', ['slug' => 'exact-race-target', 'role' => 'CURRENT_CANONICAL']));
        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE slug = :slug AND claim_role = :role', ['slug' => 'exact-race-target', 'role' => 'HISTORICAL_CANONICAL']));
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE claim_role = :role', ['role' => 'CURRENT_CANONICAL']));
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE slug IN (:first, :second) AND claim_role = :role', ['first' => 'exact-old-a', 'second' => 'exact-old-b', 'role' => 'CURRENT_CANONICAL']));
        self::assertSame(3, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_history'));
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_bindings WHERE revision = 1'));
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_bindings WHERE revision = 2'));
    }

    public function testConcurrentGeneratedChangesSkipLostBaseWithoutSyntheticHistory(): void
    {
        self::assertTrue(function_exists('proc_open'), 'WU-05 real lifecycle concurrency evidence requires proc_open.');
        $service = $this->service();
        $service->assignExact(new AssignExactCommand($this->identity('generated-race-a'), 'generated-old-a', null, new AuditContextDTO()));
        $service->assignExact(new AssignExactCommand($this->identity('generated-race-b'), 'generated-old-b', null, new AuditContextDTO()));

        $results = $this->runWorkers([
            ['generated-race-a', 'generated-race', 'generated', 'READ COMMITTED'],
            ['generated-race-b', 'generated-race', 'generated', 'READ COMMITTED'],
        ]);

        self::assertCount(2, array_filter($results, static fn(array $result): bool => $result['status'] === 'OK'));
        $values = array_map(static fn(array $result): string => $result['value'], $results);
        sort($values);
        self::assertSame(['generated-race', 'generated-race-2'], $values);
        self::assertSame(4, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry'));
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE slug = :slug AND claim_role = :role', ['slug' => 'generated-race', 'role' => 'CURRENT_CANONICAL']));
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE slug = :slug AND claim_role = :role', ['slug' => 'generated-race-2', 'role' => 'CURRENT_CANONICAL']));
        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE slug IN (:first, :second) AND claim_role = :role', ['first' => 'generated-race', 'second' => 'generated-race-2', 'role' => 'HISTORICAL_CANONICAL']));
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE slug IN (:first, :second) AND claim_role = :role', ['first' => 'generated-old-a', 'second' => 'generated-old-b', 'role' => 'HISTORICAL_CANONICAL']));
        self::assertSame(4, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_history'));
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_bindings WHERE revision = 2'));
    }

    public function testCrossBindingAliasAddsDoNotInvertRetainedAndCandidateClaims(): void
    {
        self::assertTrue(function_exists('proc_open'), 'WU-05 real lifecycle concurrency evidence requires proc_open.');
        $service = $this->service();
        $service->assignExact(new AssignExactCommand($this->identity('cross-alias-a'), 'z', null, new AuditContextDTO()));
        $service->assignExact(new AssignExactCommand($this->identity('cross-alias-b'), 'a', null, new AuditContextDTO()));

        $results = $this->runWorkers([
            ['cross-alias-a', 'a', 'alias', 'READ COMMITTED'],
            ['cross-alias-b', 'z', 'alias', 'READ COMMITTED'],
        ]);

        self::assertCount(2, $results);
        foreach ($results as $result) {
            self::assertSame('ERR', $result['status']);
            self::assertSame(SlugAlreadyClaimedException::class, $result['class']);
        }
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry'));
        self::assertSame(1, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'cross-alias-a']));
        self::assertSame(1, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'cross-alias-b']));
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_history'));
    }

    /** @param list<list<string>> $arguments
     *  @return list<array{status: string, value: string, class: string}>
     */
    private function runWorkers(array $arguments): array
    {
        $workers = [];
        foreach ($arguments as $workerArguments) {
            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = proc_open(
                [PHP_BINARY, __DIR__ . '/SlugLifecycleRaceWorker.php', ...$workerArguments],
                $descriptors,
                $pipes,
                dirname(__DIR__, 3),
            );
            if (! is_resource($process)) {
                self::fail('Unable to start lifecycle race worker.');
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
            $results[] = [
                'status' => $parts[0],
                'value' => $parts[0] === 'OK' ? ($parts[1] ?? '') : '',
                'class' => $parts[0] === 'ERR' ? ($parts[1] ?? '') : '',
            ];
            stream_set_blocking($pipes[2], false);
            $diagnostic = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), trim((string) $diagnostic));
        }

        return $results;
    }

    private function service(): SlugLifecycleService
    {
        $profiles = new SlugProfileRegistry();
        $profiles->register(new TestSlugProfile());
        return SlugLifecycleServiceFactory::create($this->pdo, $profiles, new TestReservedSlugPolicy(), $this->clock());
    }

    private function identity(string $entityKey): BindingIdentityDTO
    {
        return new BindingIdentityDTO(
            new ScopeProfileRequestDTO(new SlugScope('lifecycle-system', null, null), new SlugProfileKey('ascii-v1')),
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
