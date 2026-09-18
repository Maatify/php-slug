<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\System\Concurrency;

use Maatify\Slug\Lifecycle\Command\AddAliasCommand;
use Maatify\Slug\Lifecycle\Command\AssignExactCommand;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Registry\DTO\BindingIdentityDTO;
use Maatify\Slug\Scope\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Engine\SlugEngine;
use Maatify\Slug\Engine\SlugEngineFactory;
use Maatify\Slug\Registry\Enum\BindingStatusEnum;
use Maatify\Slug\Exception\SlugRevisionConflictException;
use Maatify\Slug\Registry\Value\EntityReference;
use Maatify\Slug\Profile\Value\SlugProfileKey;
use Maatify\Slug\Profile\Registry\SlugProfileRegistry;
use Maatify\Slug\Scope\Value\SlugScope;
use Maatify\Slug\Tests\Integration\Schema\MySqlIntegrationTestCase;
use Maatify\Slug\Tests\Unit\Allocation\TestReservedSlugPolicy;
use Maatify\Slug\Tests\Unit\Allocation\TestSlugProfile;

final class TransferAndTransitionMutationConcurrencyTest extends MySqlIntegrationTestCase
{
    public function testTransferAgainstSourceBindingMutationHasOneWinnerAndNoPartialRows(): void
    {
        $engine = $this->engine();
        $source = $this->identity('transfer-source-race', 'source');
        $target = $this->identity('transfer-source-race', 'target');
        $engine->assignExact(new AssignExactCommand($source, 'source-main', null, new AuditContextDTO()));
        $engine->addAlias(new AddAliasCommand($source, 'moved-alias', 1, new AuditContextDTO()));
        $engine->assignExact(new AssignExactCommand($target, 'target-main', null, new AuditContextDTO()));

        $results = $this->runWorkers([
            ['transfer', 'transfer-source-race', 'source', 'transfer-source-race', 'target', 'moved-alias', '2', '1'],
            ['change', 'transfer-source-race', 'source', '', '', 'source-replacement', '2', '0'],
        ]);
        $winner = $this->assertOneWinner($results);

        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_bindings'));
        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_operations'));
        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_operation_bindings'));

        if ($winner === 'TRANSFER') {
            self::assertSame(3, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry'));
            self::assertSame(3, $this->bindingRevision('source'));
            self::assertSame(2, $this->bindingRevision('target'));
            self::assertSame(5, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_history'));
            self::assertSame(0, $this->claimCount('source', 'moved-alias'));
            self::assertSame(1, $this->claimCount('target', 'moved-alias'));
            self::assertSame('CURRENT_CANONICAL', $this->claimRole('source', 'source-main'));
            self::assertSame('CURRENT_CANONICAL', $this->claimRole('target', 'target-main'));
            self::assertSame('ACTIVE_ALIAS', $this->claimRole('target', 'moved-alias'));
            self::assertSame(2, $this->scalarInt("SELECT COUNT(*) FROM maa_slug_history WHERE event_type IN ('OWNERSHIP_TRANSFERRED_OUT', 'OWNERSHIP_TRANSFERRED_IN')"));
            self::assertSame(0, $this->scalarInt("SELECT COUNT(*) FROM maa_slug_history WHERE event_type = 'CHANGED'"));
        } else {
            self::assertSame('CHANGE', $winner);
            self::assertSame(4, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry'));
            self::assertSame(3, $this->bindingRevision('source'));
            self::assertSame(1, $this->bindingRevision('target'));
            self::assertSame(4, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_history'));
            self::assertSame(1, $this->claimCount('source', 'moved-alias'));
            self::assertSame(0, $this->claimCount('target', 'moved-alias'));
            self::assertSame('CURRENT_CANONICAL', $this->claimRole('source', 'source-replacement'));
            self::assertSame('HISTORICAL_CANONICAL', $this->claimRole('source', 'source-main'));
            self::assertSame('ACTIVE_ALIAS', $this->claimRole('source', 'moved-alias'));
            self::assertSame(0, $this->scalarInt("SELECT COUNT(*) FROM maa_slug_history WHERE event_type IN ('OWNERSHIP_TRANSFERRED_OUT', 'OWNERSHIP_TRANSFERRED_IN')"));
            self::assertSame(1, $this->scalarInt("SELECT COUNT(*) FROM maa_slug_history WHERE event_type = 'CHANGED'"));
        }
    }

    public function testTransitionAgainstAffectedBindingMutationHasOneWinnerAndNoPartialRows(): void
    {
        $engine = $this->engine();
        $source = $this->identity('transition-source-race', 'source');
        $engine->assignExact(new AssignExactCommand($source, 'transition-main', null, new AuditContextDTO()));

        $results = $this->runWorkers([
            ['transition', 'transition-source-race', 'source', 'transition-target-race', '', 'transition-target', '1', '0'],
            ['change', 'transition-source-race', 'source', '', '', 'transition-replacement', '1', '0'],
        ]);
        $winner = $this->assertOneWinner($results);

        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_operations'));
        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_operation_bindings'));

        if ($winner === 'TRANSITION') {
            self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_bindings'));
            self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry'));
            self::assertSame(3, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_history'));
            self::assertSame(2, $this->bindingRevision('source', 'transition-source-race'));
            self::assertSame(1, $this->bindingRevision('source', 'transition-target-race'));
            self::assertSame(BindingStatusEnum::INACTIVE->value, $this->bindingStatus('source'));
            self::assertSame(BindingStatusEnum::ACTIVE->value, $this->bindingStatus('source', 'transition-target-race'));
            self::assertSame('CURRENT_CANONICAL', $this->claimRole('source', 'transition-main', 'transition-source-race'));
            self::assertSame('CURRENT_CANONICAL', $this->claimRole('source', 'transition-target', 'transition-target-race'));
            self::assertSame(1, $this->scalarInt("SELECT COUNT(*) FROM maa_slug_history WHERE event_type = 'SCOPE_TRANSITIONED_OUT'"));
            self::assertSame(1, $this->scalarInt("SELECT COUNT(*) FROM maa_slug_history WHERE event_type = 'SCOPE_TRANSITIONED_IN'"));
            self::assertSame(0, $this->scalarInt("SELECT COUNT(*) FROM maa_slug_history WHERE event_type = 'CHANGED'"));
        } else {
            self::assertSame('CHANGE', $winner);
            self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_bindings'));
            self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry'));
            self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_history'));
            self::assertSame(2, $this->bindingRevision('source', 'transition-source-race'));
            self::assertSame(BindingStatusEnum::ACTIVE->value, $this->bindingStatus('source'));
            self::assertSame('CURRENT_CANONICAL', $this->claimRole('source', 'transition-replacement', 'transition-source-race'));
            self::assertSame('HISTORICAL_CANONICAL', $this->claimRole('source', 'transition-main', 'transition-source-race'));
            self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_scopes WHERE namespace = :namespace', ['namespace' => 'transition-target-race']));
            self::assertSame(1, $this->scalarInt("SELECT COUNT(*) FROM maa_slug_history WHERE event_type = 'CHANGED'"));
            self::assertSame(0, $this->scalarInt("SELECT COUNT(*) FROM maa_slug_history WHERE event_type LIKE 'SCOPE_TRANSITIONED_%'"));
        }
    }

    /** @param list<array{status: string, operation: string, class: string}> $results */
    private function assertOneWinner(array $results): string
    {
        $successes = array_values(array_filter($results, static fn(array $result): bool => $result['status'] === 'OK'));
        $failures = array_values(array_filter($results, static fn(array $result): bool => $result['status'] === 'ERR'));

        self::assertCount(1, $successes);
        self::assertCount(1, $failures);
        self::assertSame(SlugRevisionConflictException::class, $failures[0]['class']);

        return $successes[0]['operation'];
    }

    /** @param list<list<string>> $arguments
     *  @return list<array{status: string, operation: string, class: string}>
     */
    private function runWorkers(array $arguments): array
    {
        if (! function_exists('proc_open')) {
            self::fail('WU-07 real concurrency evidence requires proc_open.');
        }

        $workers = [];
        foreach ($arguments as $workerArguments) {
            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = proc_open(
                [PHP_BINARY, __DIR__ . '/TransferAndTransitionMutationWorker.php', ...$workerArguments],
                $descriptors,
                $pipes,
                dirname(__DIR__, 3),
            );
            if (! is_resource($process)) {
                self::fail('Unable to start a WU-07 concurrency worker.');
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
            self::assertIsString($line, 'WU-07 worker did not complete within the deadlock guard timeout.');
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

    private function engine(): SlugEngine
    {
        $profiles = new SlugProfileRegistry();
        $profiles->register(new TestSlugProfile());

        return SlugEngineFactory::create($this->pdo, $profiles, new TestReservedSlugPolicy(), $this->clock());
    }

    private function identity(string $namespace, string $entityKey): BindingIdentityDTO
    {
        return new BindingIdentityDTO(
            new ScopeProfileRequestDTO(new SlugScope($namespace, null, null), new SlugProfileKey('ascii-v1')),
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

    private function bindingRevision(string $entityKey, string $namespace = 'transfer-source-race'): int
    {
        return $this->scalarInt(
            'SELECT b.revision FROM maa_slug_bindings b INNER JOIN maa_slug_scopes s ON s.id = b.scope_id WHERE s.namespace = :namespace AND b.entity_key = :entity_key',
            ['namespace' => $namespace, 'entity_key' => $entityKey],
        );
    }

    private function bindingStatus(string $entityKey, string $namespace = 'transition-source-race'): string
    {
        $statement = $this->pdo->prepare(
            'SELECT b.status FROM maa_slug_bindings b INNER JOIN maa_slug_scopes s ON s.id = b.scope_id WHERE s.namespace = :namespace AND b.entity_key = :entity_key',
        );
        self::assertNotFalse($statement);
        $statement->execute(['namespace' => $namespace, 'entity_key' => $entityKey]);

        return (string) $statement->fetchColumn();
    }

    private function claimCount(string $entityKey, string $slug, string $namespace = 'transfer-source-race'): int
    {
        return $this->scalarInt(
            'SELECT COUNT(*) FROM maa_slug_registry r INNER JOIN maa_slug_bindings b ON b.id = r.binding_id INNER JOIN maa_slug_scopes s ON s.id = r.scope_id WHERE s.namespace = :namespace AND b.entity_key = :entity_key AND r.slug = :slug',
            ['namespace' => $namespace, 'entity_key' => $entityKey, 'slug' => $slug],
        );
    }

    private function claimRole(string $entityKey, string $slug, string $namespace = 'transfer-source-race'): string
    {
        $statement = $this->pdo->prepare(
            'SELECT r.claim_role FROM maa_slug_registry r INNER JOIN maa_slug_bindings b ON b.id = r.binding_id INNER JOIN maa_slug_scopes s ON s.id = r.scope_id WHERE s.namespace = :namespace AND b.entity_key = :entity_key AND r.slug = :slug',
        );
        self::assertNotFalse($statement);
        $statement->execute(['namespace' => $namespace, 'entity_key' => $entityKey, 'slug' => $slug]);
        $role = $statement->fetchColumn();
        self::assertIsString($role);

        return $role;
    }
}
