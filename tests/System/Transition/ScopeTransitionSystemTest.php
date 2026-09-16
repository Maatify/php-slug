<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\System\Transition;

use Maatify\Slug\Command\AssignExactCommand;
use Maatify\Slug\Command\TransitionScopeCommand;
use Maatify\Slug\DTO\AuditContextDTO;
use Maatify\Slug\DTO\BindingIdentityDTO;
use Maatify\Slug\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\DTO\ScopeTransitionClaimIntentDTO;
use Maatify\Slug\Engine\SlugEngine;
use Maatify\Slug\Engine\SlugEngineFactory;
use Maatify\Slug\Enum\ClaimIntentModeEnum;
use Maatify\Slug\Enum\ScopeTransitionModeEnum;
use Maatify\Slug\Identity\EntityReference;
use Maatify\Slug\Identity\SlugProfileKey;
use Maatify\Slug\Profile\Registry\SlugProfileRegistry;
use Maatify\Slug\Scope\Value\SlugScope;
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
}
