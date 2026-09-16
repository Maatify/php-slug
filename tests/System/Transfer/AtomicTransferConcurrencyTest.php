<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\System\Transfer;

use Maatify\Slug\Command\AddAliasCommand;
use Maatify\Slug\Command\AssignExactCommand;
use Maatify\Slug\Command\ReleaseAllOwnershipCommand;
use Maatify\Slug\DTO\AuditContextDTO;
use Maatify\Slug\DTO\BindingIdentityDTO;
use Maatify\Slug\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\DTO\TransferReplacementIntentDTO;
use Maatify\Slug\Enum\ClaimIntentModeEnum;
use Maatify\Slug\Exception\SlugAlreadyClaimedException;
use Maatify\Slug\Exception\SlugRevisionConflictException;
use Maatify\Slug\Identity\EntityReference;
use Maatify\Slug\Identity\SlugProfileKey;
use Maatify\Slug\Infrastructure\Persistence\PDO\Connection\PdoCapabilityGuard;
use Maatify\Slug\Lifecycle\SlugLifecycleService;
use Maatify\Slug\Profile\Registry\SlugProfileRegistry;
use Maatify\Slug\Scope\Value\SlugScope;
use Maatify\Slug\Tests\Integration\Schema\MySqlIntegrationTestCase;
use Maatify\Slug\Tests\Unit\Allocation\TestReservedSlugPolicy;
use Maatify\Slug\Tests\Unit\Allocation\TestSlugProfile;

final class AtomicTransferConcurrencyTest extends MySqlIntegrationTestCase
{
    public function testReverseTransfersUseOneGlobalBindingOrder(): void
    {
        $this->assertWorkersAvailable();
        $service = $this->service();
        $sourceA = $this->identity('reverse-a');
        $sourceB = $this->identity('reverse-b');
        $service->assignExact(new AssignExactCommand($sourceA, 'reverse-main-a', null, new AuditContextDTO()));
        $service->addAlias(new AddAliasCommand($sourceA, 'reverse-alias-a', 1, new AuditContextDTO()));
        $service->assignExact(new AssignExactCommand($sourceB, 'reverse-main-b', null, new AuditContextDTO()));
        $service->addAlias(new AddAliasCommand($sourceB, 'reverse-alias-b', 1, new AuditContextDTO()));

        $results = $this->runWorkers([
            ['transfer', 'reverse-a', 'reverse-b', 'reverse-alias-a', '2', '2'],
            ['transfer', 'reverse-b', 'reverse-a', 'reverse-alias-b', '2', '2'],
        ]);
        $successes = array_values(array_filter($results, static fn(array $result): bool => $result['status'] === 'OK'));
        $failures = array_values(array_filter($results, static fn(array $result): bool => $result['status'] === 'ERR'));

        self::assertCount(1, $successes);
        self::assertCount(1, $failures);
        self::assertSame(SlugRevisionConflictException::class, $failures[0]['class']);
        self::assertSame(4, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry'));
        self::assertSame(3, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'reverse-a']));
        self::assertSame(3, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'reverse-b']));
        self::assertSame(6, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_history'));
    }

    public function testTransferAndBindingMutationHaveNoLockCycle(): void
    {
        $this->assertWorkersAvailable();
        $service = $this->service();
        $source = $this->identity('mutation-source');
        $target = $this->identity('mutation-target');
        $service->assignExact(new AssignExactCommand($source, 'mutation-main', null, new AuditContextDTO()));
        $service->addAlias(new AddAliasCommand($source, 'mutation-moved', 1, new AuditContextDTO()));
        $service->assignExact(new AssignExactCommand($target, 'mutation-target', null, new AuditContextDTO()));

        $results = $this->runWorkers([
            ['transfer', 'mutation-source', 'mutation-target', 'mutation-moved', '2', '1'],
            ['alias', 'mutation-target', '', 'mutation-competing-alias', '1', '0'],
        ]);
        $successes = array_values(array_filter($results, static fn(array $result): bool => $result['status'] === 'OK'));
        $failures = array_values(array_filter($results, static fn(array $result): bool => $result['status'] === 'ERR'));

        self::assertCount(1, $successes);
        self::assertCount(1, $failures);
        self::assertSame(SlugRevisionConflictException::class, $failures[0]['class']);
        $winner = $successes[0]['operation'];
        if ($winner === 'TRANSFER') {
            self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE binding_id = (SELECT id FROM maa_slug_bindings WHERE entity_key = :entity_key) AND slug = :slug', ['entity_key' => 'mutation-source', 'slug' => 'mutation-moved']));
            self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE binding_id = (SELECT id FROM maa_slug_bindings WHERE entity_key = :entity_key) AND slug = :slug', ['entity_key' => 'mutation-target', 'slug' => 'mutation-moved']));
            self::assertSame(3, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry'));
        } else {
            self::assertSame('ALIAS', $winner);
            self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE binding_id = (SELECT id FROM maa_slug_bindings WHERE entity_key = :entity_key) AND slug = :slug', ['entity_key' => 'mutation-source', 'slug' => 'mutation-moved']));
            self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE binding_id = (SELECT id FROM maa_slug_bindings WHERE entity_key = :entity_key) AND slug = :slug', ['entity_key' => 'mutation-target', 'slug' => 'mutation-competing-alias']));
            self::assertSame(4, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry'));
        }
    }

    public function testTransferAndCompetingClaimUseUniqueSlugAuthority(): void
    {
        $this->assertWorkersAvailable();
        $service = $this->service();
        $source = $this->identity('claim-source');
        $target = $this->identity('claim-target');
        $service->assignExact(new AssignExactCommand($source, 'claim-main', null, new AuditContextDTO()));
        $service->addAlias(new AddAliasCommand($source, 'claim-moved', 1, new AuditContextDTO()));
        $service->assignExact(new AssignExactCommand($target, 'claim-target', null, new AuditContextDTO()));
        $competitor = $this->identity('claim-competitor');
        $service->assignExact(new AssignExactCommand($competitor, 'claim-competitor-main', null, new AuditContextDTO()));
        $service->releaseAllOwnership(new \Maatify\Slug\Command\ReleaseAllOwnershipCommand($competitor, 1, new AuditContextDTO()));

        $results = $this->runWorkers([
            ['transfer', 'claim-source', 'claim-target', 'claim-moved', '2', '1'],
            ['claim', 'claim-competitor', '', 'claim-moved', '2', '0'],
        ]);
        $successes = array_values(array_filter($results, static fn(array $result): bool => $result['status'] === 'OK'));
        $failures = array_values(array_filter($results, static fn(array $result): bool => $result['status'] === 'ERR'));

        self::assertCount(1, $successes);
        self::assertCount(1, $failures);
        $winner = $successes[0]['operation'];
        if ($winner === 'TRANSFER') {
            self::assertSame(SlugAlreadyClaimedException::class, $failures[0]['class']);
            self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE slug = :slug AND binding_id = (SELECT id FROM maa_slug_bindings WHERE entity_key = :entity_key)', ['slug' => 'claim-moved', 'entity_key' => 'claim-target']));
            self::assertSame(3, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry'));
        } else {
            self::assertSame('CLAIM', $winner);
            self::assertSame(SlugAlreadyClaimedException::class, $failures[0]['class']);
            self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE slug = :slug AND binding_id = (SELECT id FROM maa_slug_bindings WHERE entity_key = :entity_key)', ['slug' => 'claim-moved', 'entity_key' => 'claim-competitor']));
            self::assertSame(4, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry'));
        }
    }

    public function testCurrentTransferAndCompetingReplacementWriterHaveOneDeterministicWinner(): void
    {
        $this->assertWorkersAvailable();
        $service = $this->service();
        $source = $this->identity('current-replacement-source');
        $target = $this->identity('current-replacement-target');
        $competitor = $this->identity('current-replacement-owner');
        $service->assignExact(new AssignExactCommand($source, 'current-moved', null, new AuditContextDTO()));
        $service->assignExact(new AssignExactCommand($target, 'current-target', null, new AuditContextDTO()));
        $service->releaseAllOwnership(new ReleaseAllOwnershipCommand($target, 1, new AuditContextDTO()));
        $service->assignExact(new AssignExactCommand($competitor, 'current-owner-main', null, new AuditContextDTO()));
        $service->releaseAllOwnership(new ReleaseAllOwnershipCommand($competitor, 1, new AuditContextDTO()));

        $results = $this->runWorkers([
            ['current-transfer', 'current-replacement-source', 'current-replacement-target', 'current-moved', '1', '2', 'EXACT', 'current-replacement', 'READ COMMITTED'],
            ['claim', 'current-replacement-owner', '', 'current-replacement', '2', '0'],
        ]);
        $successes = array_values(array_filter($results, static fn(array $result): bool => $result['status'] === 'OK'));
        $failures = array_values(array_filter($results, static fn(array $result): bool => $result['status'] === 'ERR'));

        self::assertCount(1, $successes);
        self::assertCount(1, $failures);
        self::assertSame(SlugAlreadyClaimedException::class, $failures[0]['class']);
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE slug = :slug', ['slug' => 'current-replacement']));
        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE slug = :slug AND claim_role = :role', ['slug' => 'current-replacement', 'role' => 'HISTORICAL_CANONICAL']));
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry'));
        if ($successes[0]['operation'] === 'TRANSFER') {
            self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE slug = :slug AND binding_id = (SELECT id FROM maa_slug_bindings WHERE entity_key = :entity_key)', ['slug' => 'current-moved', 'entity_key' => 'current-replacement-source']));
            self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE slug = :slug AND binding_id = (SELECT id FROM maa_slug_bindings WHERE entity_key = :entity_key)', ['slug' => 'current-moved', 'entity_key' => 'current-replacement-target']));
            self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE slug = :slug AND binding_id = (SELECT id FROM maa_slug_bindings WHERE entity_key = :entity_key)', ['slug' => 'current-replacement', 'entity_key' => 'current-replacement-source']));
            self::assertSame(2, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'current-replacement-source']));
            self::assertSame(3, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'current-replacement-target']));
            self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_history WHERE event_type IN (\'OWNERSHIP_TRANSFERRED_OUT\', \'OWNERSHIP_TRANSFERRED_IN\')'));
        } else {
            self::assertSame('CLAIM', $successes[0]['operation']);
            self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE slug = :slug AND binding_id = (SELECT id FROM maa_slug_bindings WHERE entity_key = :entity_key)', ['slug' => 'current-moved', 'entity_key' => 'current-replacement-source']));
            self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE slug = :slug AND binding_id = (SELECT id FROM maa_slug_bindings WHERE entity_key = :entity_key)', ['slug' => 'current-moved', 'entity_key' => 'current-replacement-target']));
            self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE slug = :slug AND binding_id = (SELECT id FROM maa_slug_bindings WHERE entity_key = :entity_key)', ['slug' => 'current-replacement', 'entity_key' => 'current-replacement-owner']));
            self::assertSame(1, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'current-replacement-source']));
            self::assertSame(2, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'current-replacement-target']));
            self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_history WHERE event_type IN (\'OWNERSHIP_TRANSFERRED_OUT\', \'OWNERSHIP_TRANSFERRED_IN\')'));
        }
    }

    public function testCrossedCurrentTransfersSerializeInReverseCommandOrder(): void
    {
        $this->assertWorkersAvailable();
        $service = $this->service();
        $sourceA = $this->identity('cross-source-a');
        $targetA = $this->identity('cross-target-a');
        $sourceB = $this->identity('cross-source-b');
        $targetB = $this->identity('cross-target-b');
        $service->assignExact(new AssignExactCommand($sourceA, 'cross-replacement-a', null, new AuditContextDTO()));
        $service->changeExact(new \Maatify\Slug\Command\ChangeExactCommand($sourceA, 'cross-moved-a', 1, new AuditContextDTO()));
        $service->assignExact(new AssignExactCommand($targetA, 'cross-target-a', null, new AuditContextDTO()));
        $service->releaseAllOwnership(new ReleaseAllOwnershipCommand($targetA, 1, new AuditContextDTO()));
        $service->assignExact(new AssignExactCommand($sourceB, 'cross-replacement-b', null, new AuditContextDTO()));
        $service->changeExact(new \Maatify\Slug\Command\ChangeExactCommand($sourceB, 'cross-moved-b', 1, new AuditContextDTO()));
        $service->assignExact(new AssignExactCommand($targetB, 'cross-target-b', null, new AuditContextDTO()));
        $service->releaseAllOwnership(new ReleaseAllOwnershipCommand($targetB, 1, new AuditContextDTO()));

        $results = $this->runWorkers([
            ['current-transfer', 'cross-source-b', 'cross-target-b', 'cross-moved-b', '2', '2', 'EXACT', 'cross-replacement-a', 'READ COMMITTED'],
            ['current-transfer', 'cross-source-a', 'cross-target-a', 'cross-moved-a', '2', '2', 'EXACT', 'cross-replacement-b', 'READ COMMITTED'],
        ]);

        self::assertCount(2, $results);
        foreach ($results as $result) {
            self::assertSame('ERR', $result['status']);
            self::assertSame(SlugAlreadyClaimedException::class, $result['class']);
        }
        self::assertSame(4, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry'));
        self::assertSame(2, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'cross-source-a']));
        self::assertSame(2, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'cross-source-b']));
        self::assertSame(2, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'cross-target-a']));
        self::assertSame(2, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'cross-target-b']));
    }

    public function testGeneratedCurrentReplacementAndEarlyCandidateWriterPreserveSequence(): void
    {
        $this->assertWorkersAvailable();
        $service = $this->service();
        $source = $this->identity('generated-current-source');
        $target = $this->identity('generated-current-target');
        $competitor = $this->identity('generated-current-writer');
        $service->assignExact(new AssignExactCommand($source, 'generated-moved', null, new AuditContextDTO()));
        $service->assignExact(new AssignExactCommand($target, 'generated-target', null, new AuditContextDTO()));
        $service->releaseAllOwnership(new ReleaseAllOwnershipCommand($target, 1, new AuditContextDTO()));
        $service->assignExact(new AssignExactCommand($competitor, 'generated-writer-main', null, new AuditContextDTO()));
        $service->releaseAllOwnership(new ReleaseAllOwnershipCommand($competitor, 1, new AuditContextDTO()));

        $results = $this->runWorkers([
            ['current-transfer', 'generated-current-source', 'generated-current-target', 'generated-moved', '1', '2', 'GENERATED', 'generated-moved', 'READ COMMITTED'],
            ['claim', 'generated-current-writer', '', 'generated-moved-2', '2', '0'],
        ]);
        $transferResults = array_values(array_filter($results, static fn(array $result): bool => $result['operation'] === 'TRANSFER'));
        $writerResults = array_values(array_filter($results, static fn(array $result): bool => $result['operation'] === 'CLAIM' || ($result['status'] === 'ERR' && $result['class'] === SlugAlreadyClaimedException::class)));

        self::assertCount(1, $transferResults);
        self::assertSame('OK', $transferResults[0]['status']);
        self::assertCount(1, $writerResults);
        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE slug IN (:first, :second) AND claim_role = :role', ['first' => 'generated-moved-2', 'second' => 'generated-moved-3', 'role' => 'HISTORICAL_CANONICAL']));
        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE slug = :slug AND binding_id = (SELECT id FROM maa_slug_bindings WHERE entity_key = :entity_key)', ['slug' => 'generated-moved', 'entity_key' => 'generated-current-source']));
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE slug = :slug AND binding_id = (SELECT id FROM maa_slug_bindings WHERE entity_key = :entity_key)', ['slug' => 'generated-moved', 'entity_key' => 'generated-current-target']));
        if ($writerResults[0]['status'] === 'OK') {
            self::assertSame('CLAIM', $writerResults[0]['operation']);
            self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE slug = :slug AND binding_id = (SELECT id FROM maa_slug_bindings WHERE entity_key = :entity_key)', ['slug' => 'generated-moved-2', 'entity_key' => 'generated-current-writer']));
            self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE slug = :slug AND binding_id = (SELECT id FROM maa_slug_bindings WHERE entity_key = :entity_key)', ['slug' => 'generated-moved-3', 'entity_key' => 'generated-current-source']));
            self::assertSame(3, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry'));
            self::assertSame(2, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'generated-current-source']));
            self::assertSame(3, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'generated-current-target']));
        } else {
            self::assertSame(SlugAlreadyClaimedException::class, $writerResults[0]['class']);
            self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE slug = :slug AND binding_id = (SELECT id FROM maa_slug_bindings WHERE entity_key = :entity_key)', ['slug' => 'generated-moved-2', 'entity_key' => 'generated-current-source']));
            self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE slug = :slug', ['slug' => 'generated-moved-3']));
            self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry'));
            self::assertSame(1, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'generated-current-source']));
            self::assertSame(2, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'generated-current-target']));
        }
    }

    private function assertWorkersAvailable(): void
    {
        if (! function_exists('proc_open')) {
            self::fail('WU-05 real transfer concurrency evidence requires proc_open.');
        }
    }

    /** @param list<list<string>> $arguments
     *  @return list<array{status: string, operation: string, class: string}>
     */
    private function runWorkers(array $arguments): array
    {
        $workers = [];
        foreach ($arguments as $workerArguments) {
            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = proc_open(
                [PHP_BINARY, __DIR__ . '/AtomicTransferConcurrencyWorker.php', ...$workerArguments],
                $descriptors,
                $pipes,
                dirname(__DIR__, 3),
            );
            if (! is_resource($process)) {
                self::fail('Unable to start transfer concurrency worker.');
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
                'operation' => $parts[0] === 'OK' ? ($parts[1] ?? '') : '',
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
        return new SlugLifecycleService($this->pdo, $profiles, new TestReservedSlugPolicy(), $this->clock(), new PdoCapabilityGuard($this->pdo));
    }

    private function identity(string $entityKey): BindingIdentityDTO
    {
        return new BindingIdentityDTO(
            new ScopeProfileRequestDTO(new SlugScope('transfer-concurrency', null, null), new SlugProfileKey('ascii-v1')),
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
