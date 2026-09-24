<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Integration\Adoption;

use Maatify\Slug\Lifecycle\Command\AssignExactCommand;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Facade\SlugEngine;
use Maatify\Slug\Factory\SlugEngineFactory;
use Maatify\Slug\Lifecycle\Exception\SlugAlreadyClaimedException;
use Maatify\Slug\Lifecycle\Exception\SlugRevisionConflictException;
use Maatify\Slug\Lifecycle\ValueObject\EntityReference;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistry;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;
use Maatify\Slug\Tests\Integration\Schema\MySqlIntegrationTestCase;
use Maatify\Slug\Tests\Unit\Allocation\TestReservedSlugPolicy;
use Maatify\Slug\Tests\Unit\Allocation\TestSlugProfile;

final class AdoptionConcurrencyTest extends MySqlIntegrationTestCase
{
    public function testConcurrentCurrentAdoptionOfOneAbsentBindingHasOneWinner(): void
    {
        self::assertTrue(function_exists('proc_open'), 'WU-06 real adoption concurrency requires proc_open.');
        $engine = $this->engine();
        $engine->ensureScope($this->scope('adoption-current-race'));

        $results = $this->runWorkers([
            ['current', 'adoption-current-race', 'same-absent-binding', 'current-race-slug', 'null', '',],
            ['current', 'adoption-current-race', 'same-absent-binding', 'current-race-slug', 'null', '',],
        ]);
        self::assertCount(1, array_filter($results, static fn(array $result): bool => $result['status'] === 'OK'));
        $failures = array_values(array_filter($results, static fn(array $result): bool => $result['status'] === 'ERR'));
        self::assertCount(1, $failures);
        self::assertSame(SlugRevisionConflictException::class, $failures[0]['class']);
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_bindings'));
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry'));
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_history'));
    }

    public function testInverseHistoricalClaimsCompleteWithoutDeadlock(): void
    {
        self::assertTrue(function_exists('proc_open'), 'WU-06 real adoption concurrency requires proc_open.');
        $engine = $this->engine();
        $engine->assignExact(new AssignExactCommand($this->identity('adoption-inverse-historical', 'historical-left'), 'historical-z', null, new AuditContextDTO()));
        $engine->assignExact(new AssignExactCommand($this->identity('adoption-inverse-historical', 'historical-right'), 'historical-a', null, new AuditContextDTO()));

        $results = $this->runWorkers([
            ['historical', 'adoption-inverse-historical', 'historical-left', 'historical-a', '1', '',],
            ['historical', 'adoption-inverse-historical', 'historical-right', 'historical-z', '1', '',],
        ]);
        self::assertCount(2, $results);
        foreach ($results as $result) {
            self::assertSame('ERR', $result['status']);
            self::assertSame(SlugAlreadyClaimedException::class, $result['class']);
        }
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry'));
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_history'));
    }

    public function testInverseAliasClaimsCompleteWithoutDeadlock(): void
    {
        self::assertTrue(function_exists('proc_open'), 'WU-06 real adoption concurrency requires proc_open.');
        $engine = $this->engine();
        $engine->assignExact(new AssignExactCommand($this->identity('adoption-inverse-alias', 'alias-left'), 'alias-z', null, new AuditContextDTO()));
        $engine->assignExact(new AssignExactCommand($this->identity('adoption-inverse-alias', 'alias-right'), 'alias-a', null, new AuditContextDTO()));

        $results = $this->runWorkers([
            ['alias', 'adoption-inverse-alias', 'alias-left', 'alias-a', '1', '',],
            ['alias', 'adoption-inverse-alias', 'alias-right', 'alias-z', '1', '',],
        ]);
        self::assertCount(2, $results);
        foreach ($results as $result) {
            self::assertSame('ERR', $result['status']);
            self::assertSame(SlugAlreadyClaimedException::class, $result['class']);
        }
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry'));
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_history'));
    }

    public function testAdoptionAndAliasWriterCompeteOnOneSlugWithNoPartialHistory(): void
    {
        self::assertTrue(function_exists('proc_open'), 'WU-06 real adoption concurrency requires proc_open.');
        $engine = $this->engine();
        $engine->assignExact(new AssignExactCommand($this->identity('adoption-writer-race', 'adoption-owner'), 'adoption-owner-current', null, new AuditContextDTO()));
        $engine->assignExact(new AssignExactCommand($this->identity('adoption-writer-race', 'writer-owner'), 'writer-owner-current', null, new AuditContextDTO()));

        $results = $this->runWorkers([
            ['historical', 'adoption-writer-race', 'adoption-owner', 'shared-writer-slug', '1', '',],
            ['writer', 'adoption-writer-race', 'writer-owner', 'shared-writer-slug', '1', '',],
        ]);
        self::assertCount(1, array_filter($results, static fn(array $result): bool => $result['status'] === 'OK'));
        $failures = array_values(array_filter($results, static fn(array $result): bool => $result['status'] === 'ERR'));
        self::assertCount(1, $failures);
        self::assertSame(SlugAlreadyClaimedException::class, $failures[0]['class']);
        self::assertSame(3, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry'));
        self::assertSame(3, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_history'));
    }

    private function engine(): SlugEngine
    {
        $profiles = new SlugProfileRegistry();
        $profiles->register(new TestSlugProfile());
        return SlugEngineFactory::create($this->pdo, $profiles, new TestReservedSlugPolicy(), $this->clock());
    }

    private function identity(string $namespace, string $entityKey): BindingIdentityDTO
    {
        return new BindingIdentityDTO($this->scope($namespace), new EntityReference('product', $entityKey));
    }

    private function scope(string $namespace): ScopeProfileRequestDTO
    {
        return new ScopeProfileRequestDTO(new SlugScope($namespace, null, null), new SlugProfileKey('ascii-v1'));
    }

    /** @param list<list<string>> $arguments
     *  @return list<array{status: string, value: string, class: string, replayed: string}>
     */
    private function runWorkers(array $arguments): array
    {
        $workers = [];
        foreach ($arguments as $workerArguments) {
            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = proc_open([PHP_BINARY, __DIR__ . '/AdoptionConcurrencyWorker.php', ...$workerArguments], $descriptors, $pipes, dirname(__DIR__, 3));
            if (! is_resource($process)) {
                self::fail('Unable to start adoption concurrency worker.');
            }
            $workers[] = [$process, $pipes];
        }

        foreach ($workers as [$process, $pipes]) {
            stream_set_timeout($pipes[1], 10);
            self::assertSame("READY\n", fgets($pipes[1]));
        }
        foreach ($workers as [$process, $pipes]) {
            fwrite($pipes[0], "GO\n");
            fclose($pipes[0]);
        }

        $results = [];
        foreach ($workers as [$process, $pipes]) {
            stream_set_timeout($pipes[1], 10);
            $line = fgets($pipes[1]);
            self::assertIsString($line, 'Adoption worker did not complete within the deadlock guard timeout.');
            $parts = explode("\t", trim($line));
            $results[] = [
                'status' => $parts[0],
                'value' => $parts[0] === 'OK' ? ($parts[1] ?? '') : '',
                'class' => $parts[0] === 'ERR' ? ($parts[1] ?? '') : '',
                'replayed' => $parts[2] ?? '',
            ];
            stream_set_blocking($pipes[2], false);
            $diagnostic = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), trim((string) $diagnostic));
        }

        return $results;
    }

    private function scalarInt(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement);
        return (int) $statement->fetchColumn();
    }
}
