<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\System\Transition;

use Maatify\Slug\Lifecycle\Command\AssignExactCommand;
use Maatify\Slug\Lifecycle\Command\TransitionScopeCommand;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeTransitionClaimIntentDTO;
use Maatify\Slug\Facade\SlugEngine;
use Maatify\Slug\Factory\SlugEngineFactory;
use Maatify\Slug\Lifecycle\Enum\ClaimIntentModeEnum;
use Maatify\Slug\Lifecycle\Enum\ScopeTransitionModeEnum;
use Maatify\Slug\Lifecycle\Exception\SlugRevisionConflictException;
use Maatify\Slug\Lifecycle\Exception\SlugScopeProfileMismatchException;
use Maatify\Slug\Lifecycle\ValueObject\EntityReference;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistry;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;
use Maatify\Slug\Tests\Integration\Schema\MySqlIntegrationTestCase;
use Maatify\Slug\Tests\Unit\Allocation\TestReservedSlugPolicy;
use Maatify\Slug\Tests\Unit\Allocation\TestSlugProfile;

final class ScopeTransitionSystemTest extends MySqlIntegrationTestCase
{
    public function testParallelTransitionLeavesSourceUntouchedAndActivatesNewTarget(): void
    {
        $engine = $this->engine();
        $source = $this->identity('parallel-source');
        $target = $this->scope('transition-target');
        $engine->assignExact(new AssignExactCommand($source, 'source-slug', null, new AuditContextDTO()));

        $result = $engine->transitionScope(new TransitionScopeCommand(
            $source,
            $target,
            ScopeTransitionModeEnum::PARALLEL,
            new ScopeTransitionClaimIntentDTO(ClaimIntentModeEnum::EXACT, 'target-slug'),
            1,
            new AuditContextDTO(),
        ));

        self::assertFalse($result->sourceResult->mutated);
        self::assertSame(1, $result->sourceRevision);
        self::assertSame('ACTIVE', $result->sourceAfter->state->status->value);
        self::assertSame(1, $result->targetRevision);
        self::assertSame('target-slug', $result->targetAfter->state->currentSlug?->value);
        self::assertSame(['SCOPE_TRANSITIONED_IN'], array_map(static fn($event): string => $event->eventType->value, $result->historyEvents));
        $statement = $this->pdo->query("SELECT revision FROM maa_slug_bindings WHERE entity_key = 'parallel-source' AND scope_id = (SELECT id FROM maa_slug_scopes WHERE namespace = 'catalog')");
        self::assertNotFalse($statement);
        self::assertSame(1, (int) $statement->fetchColumn());
    }

    public function testMoveTransitionUpdatesBothParticipantsAtomicallyAndReplaysSnapshot(): void
    {
        $engine = $this->engine();
        $source = $this->identity('move-source');
        $target = $this->scope('transition-move-target');
        $engine->assignExact(new AssignExactCommand($source, 'move-source-slug', null, new AuditContextDTO()));
        $audit = new AuditContextDTO(idempotencyKey: 'transition-move-key');
        $command = new TransitionScopeCommand(
            $source,
            $target,
            ScopeTransitionModeEnum::MOVE,
            new ScopeTransitionClaimIntentDTO(ClaimIntentModeEnum::EXACT, 'move-target-slug'),
            1,
            $audit,
        );

        $first = $engine->transitionScope($command);
        $snapshot = $this->operationColumn('transition-move-key', 'result_snapshot');
        $replay = $engine->transitionScope($command);

        self::assertFalse($first->replayed);
        self::assertTrue($replay->replayed);
        self::assertSame(2, $first->sourceRevision);
        self::assertSame('INACTIVE', $first->sourceAfter->state->status->value);
        self::assertSame(1, $first->targetRevision);
        self::assertSame(['SCOPE_TRANSITIONED_OUT', 'SCOPE_TRANSITIONED_IN'], array_map(static fn($event): string => $event->eventType->value, $first->historyEvents));
        $operations = $this->pdo->query('SELECT COUNT(*) FROM maa_slug_operations');
        self::assertNotFalse($operations);
        self::assertSame(1, (int) $operations->fetchColumn());
        $participants = $this->pdo->query('SELECT COUNT(*) FROM maa_slug_operation_bindings');
        self::assertNotFalse($participants);
        self::assertSame(2, (int) $participants->fetchColumn());
        self::assertSame('TRANSITION_SCOPE', $this->operationColumn('transition-move-key', 'operation_type'));
        self::assertSame('transition', $this->operationColumn('transition-move-key', 'result_type'));
        self::assertSame('1', $this->operationColumn('transition-move-key', 'result_schema_version'));
        self::assertSame($snapshot, $this->operationColumn('transition-move-key', 'result_snapshot'));

        $engine->releaseAllOwnership(new \Maatify\Slug\Lifecycle\Command\ReleaseAllOwnershipCommand($targetIdentity = new BindingIdentityDTO($target, $source->entity), 1, new AuditContextDTO()));
        $replayAfterLiveChange = $engine->transitionScope($command);
        self::assertTrue($replayAfterLiveChange->replayed);
        self::assertSame($snapshot, $this->operationColumn('transition-move-key', 'result_snapshot'));
    }

    public function testInverseTransitionsUseOneScopeOrderWithoutDeadlock(): void
    {
        self::assertTrue(function_exists('proc_open'), 'WU-06 real transition concurrency requires proc_open.');
        $engine = $this->engine();
        $left = $this->identityInScope('transition-inverse-a', 'inverse-left');
        $right = $this->identityInScope('transition-inverse-b', 'inverse-right');
        $engine->assignExact(new AssignExactCommand($left, 'inverse-left-slug', null, new AuditContextDTO()));
        $engine->assignExact(new AssignExactCommand($right, 'inverse-right-slug', null, new AuditContextDTO()));

        $results = $this->runWorkers([
            ['transition-inverse-a', 'transition-inverse-b', 'inverse-left', 'exact', 'inverse-target-left', '1', ''],
            ['transition-inverse-b', 'transition-inverse-a', 'inverse-right', 'exact', 'inverse-target-right', '1', ''],
        ]);

        self::assertCount(2, array_filter($results, static fn(array $result): bool => $result['status'] === 'OK'));
        self::assertSame(4, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_bindings'));
        self::assertSame(4, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry'));
        self::assertSame(6, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_history'));
    }

    public function testSameAbsentTargetBindingHasOneWinnerAndOneRevisionConflict(): void
    {
        self::assertTrue(function_exists('proc_open'), 'WU-06 real transition concurrency requires proc_open.');
        $engine = $this->engine();
        $engine->ensureScope($this->scope('transition-race-target'));
        $engine->assignExact(new AssignExactCommand($this->identityInScope('transition-race-source-a', 'shared-target'), 'source-a', null, new AuditContextDTO()));
        $engine->assignExact(new AssignExactCommand($this->identityInScope('transition-race-source-b', 'shared-target'), 'source-b', null, new AuditContextDTO()));

        self::assertLessThan($this->scopeId('transition-race-source-a'), $this->scopeId('transition-race-target'));
        self::assertLessThan($this->scopeId('transition-race-source-b'), $this->scopeId('transition-race-target'));

        $results = $this->runWorkers([
            ['transition-race-source-a', 'transition-race-target', 'shared-target', 'exact', 'shared-target-slug', '1', ''],
            ['transition-race-source-b', 'transition-race-target', 'shared-target', 'exact', 'shared-target-slug', '1', ''],
        ]);

        self::assertCount(1, array_filter($results, static fn(array $result): bool => $result['status'] === 'OK'));
        $failures = array_values(array_filter($results, static fn(array $result): bool => $result['status'] === 'ERR'));
        self::assertCount(1, $failures);
        self::assertSame(SlugRevisionConflictException::class, $failures[0]['class']);
        self::assertSame(3, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_bindings'));
        self::assertSame(3, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry'));
        self::assertSame(4, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_history'));
    }

    public function testGeneratedTransitionCandidatesRaceWithoutSyntheticClaims(): void
    {
        self::assertTrue(function_exists('proc_open'), 'WU-06 real transition concurrency requires proc_open.');
        $engine = $this->engine();
        $engine->assignExact(new AssignExactCommand($this->identityInScope('transition-generated-source-a', 'generated-left'), 'source-left', null, new AuditContextDTO()));
        $engine->assignExact(new AssignExactCommand($this->identityInScope('transition-generated-source-b', 'generated-right'), 'source-right', null, new AuditContextDTO()));

        $results = $this->runWorkers([
            ['transition-generated-source-a', 'transition-generated-target', 'generated-left', 'generated', 'generated-transition', '1', ''],
            ['transition-generated-source-b', 'transition-generated-target', 'generated-right', 'generated', 'generated-transition', '1', ''],
        ]);
        $successes = array_values(array_filter($results, static fn(array $result): bool => $result['status'] === 'OK'));
        self::assertCount(2, $successes);
        $slugs = array_map(static fn(array $result): string => $result['value'], $successes);
        sort($slugs);
        self::assertSame(['generated-transition', 'generated-transition-2'], $slugs);
        self::assertSame(4, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry'));
        self::assertSame(6, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_history'));
    }

    public function testTransitionIdempotencyAndStaleOrMismatchedRequestsDoNotMutate(): void
    {
        $engine = $this->engine();
        $source = $this->identityInScope('transition-semantics-source', 'semantic-source');
        $target = $this->scope('transition-semantics-target');
        $engine->assignExact(new AssignExactCommand($source, 'semantic-source-slug', null, new AuditContextDTO()));
        $audit = new AuditContextDTO(idempotencyKey: 'transition-semantic-key');
        $command = new TransitionScopeCommand($source, $target, ScopeTransitionModeEnum::PARALLEL, new ScopeTransitionClaimIntentDTO(ClaimIntentModeEnum::EXACT, 'semantic-target-slug'), 1, $audit);
        $engine->transitionScope($command);

        try {
            $engine->transitionScope(new TransitionScopeCommand($source, $target, ScopeTransitionModeEnum::PARALLEL, new ScopeTransitionClaimIntentDTO(ClaimIntentModeEnum::EXACT, 'different-target-slug'), 1, $audit));
            self::fail('A changed transition fingerprint was accepted for one idempotency key.');
        } catch (\Maatify\Slug\Lifecycle\Exception\SlugIdempotencyConflictException $exception) {
            self::assertSame(\Maatify\Slug\Lifecycle\Exception\SlugIdempotencyConflictException::class, $exception::class);
        }

        $competing = $this->identityInScope('transition-competing-source', 'competing-source');
        $engine->assignExact(new AssignExactCommand($competing, 'competing-source-slug', null, new AuditContextDTO()));
        $competitionTarget = $this->scope('transition-competing-target');
        $engine->transitionScope(new TransitionScopeCommand($competing, $competitionTarget, ScopeTransitionModeEnum::PARALLEL, new ScopeTransitionClaimIntentDTO(ClaimIntentModeEnum::EXACT, 'competing-target-slug'), 1, new AuditContextDTO(idempotencyKey: 'competition-first')));
        try {
            $engine->transitionScope(new TransitionScopeCommand($competing, $competitionTarget, ScopeTransitionModeEnum::PARALLEL, new ScopeTransitionClaimIntentDTO(ClaimIntentModeEnum::EXACT, 'competing-other-slug'), 1, new AuditContextDTO(idempotencyKey: 'competition-second')));
            self::fail('A different idempotency key bypassed an existing target Binding.');
        } catch (SlugRevisionConflictException $exception) {
            self::assertSame(SlugRevisionConflictException::class, $exception::class);
        }

        $stale = $this->identityInScope('transition-stale-source', 'stale-source');
        $engine->assignExact(new AssignExactCommand($stale, 'stale-source-slug', null, new AuditContextDTO()));
        $beforeBindings = $this->scalarInt('SELECT COUNT(*) FROM maa_slug_bindings');
        try {
            $engine->transitionScope(new TransitionScopeCommand($stale, $this->scope('transition-stale-target'), ScopeTransitionModeEnum::MOVE, new ScopeTransitionClaimIntentDTO(ClaimIntentModeEnum::EXACT, 'stale-target-slug'), 0, new AuditContextDTO()));
            self::fail('A stale source revision was accepted.');
        } catch (SlugRevisionConflictException) {
            self::assertSame($beforeBindings, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_bindings'));
        }

        $profiles = new SlugProfileRegistry();
        $profiles->register(new TestSlugProfile());
        $profiles->register(new TestSlugProfile(new SlugProfileKey('other-v1')));
        $mismatchEngine = SlugEngineFactory::create($this->pdo, $profiles, new TestReservedSlugPolicy(), $this->clock());
        $mismatchSource = $this->identityInScope('transition-profile-source', 'profile-source');
        $mismatchEngine->assignExact(new AssignExactCommand($mismatchSource, 'profile-source-slug', null, new AuditContextDTO()));
        $mismatchTarget = new ScopeProfileRequestDTO(new SlugScope('transition-profile-target', null, null), new SlugProfileKey('other-v1'));
        $mismatchEngine->ensureScope(new ScopeProfileRequestDTO(new SlugScope('transition-profile-target', null, null), new SlugProfileKey('ascii-v1')));
        $scopesBefore = $this->scalarInt('SELECT COUNT(*) FROM maa_slug_scopes');
        $bindingsBefore = $this->scalarInt('SELECT COUNT(*) FROM maa_slug_bindings');
        try {
            $mismatchEngine->transitionScope(new TransitionScopeCommand($mismatchSource, $mismatchTarget, ScopeTransitionModeEnum::MOVE, new ScopeTransitionClaimIntentDTO(ClaimIntentModeEnum::EXACT, 'profile-target-slug'), 1, new AuditContextDTO()));
            self::fail('A target profile mismatch was accepted.');
        } catch (SlugScopeProfileMismatchException) {
            self::assertSame($scopesBefore, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_scopes'));
            self::assertSame($bindingsBefore, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_bindings'));
        }
    }

    private function engine(): SlugEngine
    {
        $profiles = new SlugProfileRegistry();
        $profiles->register(new TestSlugProfile());
        return SlugEngineFactory::create($this->pdo, $profiles, new TestReservedSlugPolicy(), $this->clock());
    }

    private function identity(string $entityKey): BindingIdentityDTO
    {
        return new BindingIdentityDTO(
            new ScopeProfileRequestDTO(new SlugScope('catalog', null, null), new SlugProfileKey('ascii-v1')),
            new EntityReference('product', $entityKey),
        );
    }

    private function scope(string $namespace): ScopeProfileRequestDTO
    {
        return new ScopeProfileRequestDTO(new SlugScope($namespace, null, null), new SlugProfileKey('ascii-v1'));
    }

    private function identityInScope(string $namespace, string $entityKey): BindingIdentityDTO
    {
        return new BindingIdentityDTO($this->scope($namespace), new EntityReference('product', $entityKey));
    }

    /** @param list<list<string>> $arguments
     *  @return list<array{status: string, value: string, class: string, replayed: string}>
     */
    private function runWorkers(array $arguments): array
    {
        $workers = [];
        foreach ($arguments as $workerArguments) {
            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = proc_open([PHP_BINARY, __DIR__ . '/ScopeTransitionConcurrencyWorker.php', ...$workerArguments], $descriptors, $pipes, dirname(__DIR__, 3));
            if (! is_resource($process)) {
                self::fail('Unable to start transition concurrency worker.');
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
            self::assertIsString($line, 'Transition worker did not complete within the deadlock guard timeout.');
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

    private function scopeId(string $namespace): int
    {
        $statement = $this->pdo->prepare('SELECT id FROM maa_slug_scopes WHERE namespace = :namespace');
        self::assertNotFalse($statement);
        $statement->execute(['namespace' => $namespace]);
        return (int) $statement->fetchColumn();
    }

    private function operationColumn(string $operationKey, string $column): string
    {
        self::assertContains($column, ['operation_type', 'result_type', 'result_schema_version', 'result_snapshot']);
        $statement = $this->pdo->prepare(
            'SELECT o.' . $column . ' FROM maa_slug_operations o '
            . 'INNER JOIN maa_slug_operation_bindings p ON p.operation_id = o.id '
            . 'WHERE p.idempotency_key = :operation_key',
        );
        self::assertNotFalse($statement);
        $statement->execute(['operation_key' => $operationKey]);
        $value = $statement->fetchColumn();
        self::assertNotFalse($value);
        return (string) $value;
    }
}
