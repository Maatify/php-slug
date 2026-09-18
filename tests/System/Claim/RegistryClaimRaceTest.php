<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\System\Claim;

use Maatify\Slug\Query\Availability\SlugAvailabilityChecker;
use Maatify\Slug\Lifecycle\Contract\ReservedSlugPolicyInterface;
use Maatify\Slug\Query\Criteria\AvailabilityCriteria;
use Maatify\Slug\Registry\DTO\BindingIdentityDTO;
use Maatify\Slug\Scope\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Exception\SlugAlreadyClaimedException;
use Maatify\Slug\Registry\Value\EntityReference;
use Maatify\Slug\Text\Value\Slug;
use Maatify\Slug\Profile\Value\SlugProfileKey;
use Maatify\Slug\Persistence\PDO\Connection\PdoCapabilityGuard;
use Maatify\Slug\Persistence\PDO\Registry\PdoRegistryRepository;
use Maatify\Slug\Persistence\PDO\Scope\PdoScopeRepository;
use Maatify\Slug\Registry\Internal\Claim\RegistryClaimCoordinator;
use Maatify\Slug\Persistence\Transaction\PdoTransactionCoordinator;
use Maatify\Slug\Profile\Registry\SlugProfileRegistry;
use Maatify\Slug\Scope\Value\SlugScope;
use Maatify\Slug\Tests\Integration\Schema\FrozenClock;
use Maatify\Slug\Tests\Integration\Schema\MySqlIntegrationTestCase;
use Maatify\Slug\Tests\Unit\Allocation\TestReservedSlugPolicy;
use Maatify\Slug\Tests\Unit\Allocation\TestSlugProfile;
use PDO;

final class RegistryClaimRaceTest extends MySqlIntegrationTestCase
{
    public function testConcurrentExactClaimsHaveOneWinnerAndOneSemanticLoser(): void
    {
        $this->assertConcurrentWorkersAvailable();
        $first = $this->identity('catalog', 'race-first');
        $second = $this->identity('catalog', 'race-second');
        $this->prepareBinding($first);
        $this->prepareBinding($second);

        $results = $this->runWorkers([$first, $second], false);
        $successes = array_values(array_filter($results, static fn(array $result): bool => $result['status'] === 'OK'));
        $failures = array_values(array_filter($results, static fn(array $result): bool => $result['status'] === 'ERR'));

        self::assertCount(1, $successes);
        self::assertSame('race-slug', $successes[0]['slug']);
        self::assertCount(1, $failures);
        self::assertSame(SlugAlreadyClaimedException::class, $failures[0]['class']);
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE slug = :slug', ['slug' => 'race-slug']));
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_bindings WHERE status = :status', ['status' => 'ACTIVE']));
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_bindings WHERE status = :status AND current_registry_id IS NOT NULL', ['status' => 'ACTIVE']));
    }

    public function testConcurrentGeneratedAllocatorsProduceBaseAndNextCandidate(): void
    {
        $this->assertConcurrentWorkersAvailable();
        $first = $this->identity('catalog', 'generated-first');
        $second = $this->identity('catalog', 'generated-second');
        $this->prepareBinding($first);
        $this->prepareBinding($second);

        $results = $this->runWorkers([$first, $second], true);
        $successes = array_values(array_filter($results, static fn(array $result): bool => $result['status'] === 'OK'));
        $slugs = array_map(static fn(array $result): string => $result['slug'], $successes);
        sort($slugs);

        self::assertCount(2, $successes);
        self::assertSame(['product', 'product-2'], $slugs);
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE scope_id = :scope_id', ['scope_id' => $this->scopeId('catalog')]));
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_bindings WHERE status = :status AND revision = :revision', ['status' => 'ACTIVE', 'revision' => 1]));
    }

    public function testAvailabilityIsStaleAdvisoryWhenAnotherConnectionClaimsBeforeRequest(): void
    {
        $profiles = new SlugProfileRegistry();
        $profiles->register(new TestSlugProfile());
        $policy = new TestReservedSlugPolicy();
        $capabilities = new PdoCapabilityGuard($this->pdo);
        $scopes = new PdoScopeRepository($this->pdo, $profiles, $this->clock(), $capabilities);
        $repository = new PdoRegistryRepository($this->pdo, $profiles, $scopes, $this->clock(), $capabilities);
        $checker = new SlugAvailabilityChecker($repository, $profiles, $policy);
        $scopeProfile = new ScopeProfileRequestDTO(new SlugScope('race-advisory', null, null), new SlugProfileKey('ascii-v1'));

        $available = $checker->check(new AvailabilityCriteria($scopeProfile, 'product'));
        self::assertSame('AVAILABLE', $available->status->value);
        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_scopes WHERE namespace = :namespace', ['namespace' => 'race-advisory']));

        $competitorPdo = $this->newTestConnection();
        $competitorCoordinator = $this->coordinator($competitorPdo, $policy);
        $competitorCoordinator->claimExact($this->identity('race-advisory', 'competitor'), 'product');

        $requester = new RegistryClaimCoordinator($repository, $profiles, $policy, new PdoTransactionCoordinator($this->pdo));
        try {
            $requester->claimExact($this->identity('race-advisory', 'requester'), 'product');
            self::fail('A stale AVAILABLE result was treated as a claim guarantee.');
        } catch (SlugAlreadyClaimedException) {
            self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE slug = :slug', ['slug' => 'product']));
            self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'competitor']));
        }
    }

    /** @param list<BindingIdentityDTO> $identities
     *  @return list<array{status: string, slug: string, class: string}>
     */
    private function runWorkers(array $identities, bool $generated): array
    {
        $pipes = [];
        $processes = [];
        foreach ($identities as $identity) {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $process = proc_open(
                [
                    PHP_BINARY,
                    __DIR__ . '/RegistryClaimRaceWorker.php',
                    $identity->scopeProfile->scope->namespace,
                    $identity->entity->entityKey,
                    $generated ? 'generated' : 'exact',
                ],
                $descriptors,
                $workerPipes,
                dirname(__DIR__, 3),
            );
            if (! is_resource($process)) {
                self::fail('Unable to start a real database race worker.');
            }
            $processes[] = $process;
            $pipes[] = $workerPipes;
        }

        foreach ($pipes as $pipe) {
            $ready = fgets($pipe[1]);
            if ($ready !== "READY\n") {
                stream_set_blocking($pipe[2], false);
                $diagnostic = stream_get_contents($pipe[2]);
                self::fail('Race worker did not reach the barrier: ' . trim((string) $diagnostic));
            }
        }
        foreach ($pipes as $pipe) {
            fwrite($pipe[0], "GO\n");
            fclose($pipe[0]);
        }

        $results = [];
        foreach ($processes as $index => $process) {
            $line = fgets($pipes[$index][1]);
            self::assertIsString($line);
            $parts = explode("\t", trim($line));
            $results[] = [
                'status' => $parts[0],
                'slug' => $parts[1] ?? '',
                'class' => $parts[1] ?? '',
            ];
            stream_set_blocking($pipes[$index][2], false);
            $diagnostic = stream_get_contents($pipes[$index][2]);
            fclose($pipes[$index][1]);
            fclose($pipes[$index][2]);
            self::assertSame(0, proc_close($process), 'Race worker exited abnormally: ' . trim((string) $diagnostic));
        }

        return $results;
    }

    private function prepareBinding(BindingIdentityDTO $identity): void
    {
        $profiles = new SlugProfileRegistry();
        $profiles->register(new TestSlugProfile());
        $capabilities = new PdoCapabilityGuard($this->pdo);
        $scopeRepository = new PdoScopeRepository($this->pdo, $profiles, $this->clock(), $capabilities);
        $scopeRepository->ensureBindingPlaceholder($identity->scopeProfile, $identity->entity);
    }

    private function coordinator(PDO $pdo, ReservedSlugPolicyInterface $policy): RegistryClaimCoordinator
    {
        $profiles = new SlugProfileRegistry();
        $profiles->register(new TestSlugProfile());
        $capabilities = new PdoCapabilityGuard($pdo);
        $scopes = new PdoScopeRepository($pdo, $profiles, new FrozenClock(), $capabilities);
        $repository = new PdoRegistryRepository($pdo, $profiles, $scopes, new FrozenClock(), $capabilities);

        return new RegistryClaimCoordinator($repository, $profiles, $policy, new PdoTransactionCoordinator($pdo));
    }

    private function identity(string $namespace, string $entityKey): BindingIdentityDTO
    {
        return new BindingIdentityDTO(
            new ScopeProfileRequestDTO(new SlugScope($namespace, null, null), new SlugProfileKey('ascii-v1')),
            new EntityReference('product', $entityKey),
        );
    }

    private function assertConcurrentWorkersAvailable(): void
    {
        if (! function_exists('proc_open')) {
            self::fail('WU-04 real concurrency evidence requires proc_open.');
        }
    }

    private function scopeId(string $namespace): int
    {
        return $this->scalarInt('SELECT id FROM maa_slug_scopes WHERE namespace = :namespace', ['namespace' => $namespace]);
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
