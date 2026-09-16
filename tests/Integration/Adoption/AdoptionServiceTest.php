<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Integration\Adoption;

use DateTimeImmutable;
use Maatify\Slug\Command\AdoptAliasCommand;
use Maatify\Slug\Command\AdoptCurrentCommand;
use Maatify\Slug\Command\AdoptHistoricalCommand;
use Maatify\Slug\Command\ReleaseAllOwnershipCommand;
use Maatify\Slug\DTO\AuditContextDTO;
use Maatify\Slug\DTO\BindingIdentityDTO;
use Maatify\Slug\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Engine\SlugEngine;
use Maatify\Slug\Engine\SlugEngineFactory;
use Maatify\Slug\Exception\SlugNotFoundException;
use Maatify\Slug\Identity\EntityReference;
use Maatify\Slug\Identity\SlugProfileKey;
use Maatify\Slug\Profile\Registry\SlugProfileRegistry;
use Maatify\Slug\Scope\Value\SlugScope;
use Maatify\Slug\Tests\Integration\Schema\MySqlIntegrationTestCase;
use Maatify\Slug\Tests\Unit\Allocation\TestReservedSlugPolicy;
use Maatify\Slug\Tests\Unit\Allocation\TestSlugProfile;

final class AdoptionServiceTest extends MySqlIntegrationTestCase
{
    public function testCurrentHistoricalAndAliasAdoptionPreserveRevisionAndImportedInstant(): void
    {
        $engine = $this->engine();
        $identity = $this->identity('adoption-1');
        $original = new DateTimeImmutable('2025-12-31T03:04:05.654321+03:00');

        $current = $engine->adoptCurrent(new AdoptCurrentCommand(
            $identity,
            'Imported Current',
            $original,
            null,
            new AuditContextDTO(idempotencyKey: 'adopt-current-key'),
        ));
        self::assertSame(1, $current->after->state->revision);
        self::assertSame('CURRENT_CANONICAL', $current->adoptedClaim->role->value);
        self::assertSame('ADOPTED_CURRENT', $current->historyEvent->eventType->value);
        self::assertSame('2025-12-31T00:04:05.654321Z', $current->historyEvent->originalOccurredAt?->format('Y-m-d\TH:i:s.u\Z'));

        $replay = $engine->adoptCurrent(new AdoptCurrentCommand(
            $identity,
            'Imported Current',
            $original,
            null,
            new AuditContextDTO(idempotencyKey: 'adopt-current-key'),
        ));
        self::assertTrue($replay->replayed);
        self::assertSame($current->operationKey, $replay->operationKey);
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_history'));

        $historical = $engine->adoptHistorical(new AdoptHistoricalCommand(
            $identity,
            'Imported Historical',
            null,
            1,
            new AuditContextDTO(),
        ));
        self::assertSame(2, $historical->after->state->revision);
        self::assertSame('HISTORICAL_CANONICAL', $historical->adoptedClaim->role->value);
        self::assertSame(2, $historical->historyEvent->sequenceNo);

        $alias = $engine->adoptAlias(new AdoptAliasCommand(
            $identity,
            'Imported Alias',
            null,
            2,
            new AuditContextDTO(),
        ));
        self::assertSame(3, $alias->after->state->revision);
        self::assertSame('ACTIVE_ALIAS', $alias->adoptedClaim->role->value);
        self::assertSame('ACTIVE', $alias->after->state->status->value);
    }

    public function testCurrentAdoptionReopensOnlyWithExplicitReleasedRevisionAndHistoricalRequiresAnchor(): void
    {
        $engine = $this->engine();
        $identity = $this->identity('adoption-reopen');

        $engine->adoptCurrent(new AdoptCurrentCommand($identity, 'first-current', null, null, new AuditContextDTO()));
        $released = $engine->releaseAllOwnership(new ReleaseAllOwnershipCommand($identity, 1, new AuditContextDTO()));
        $reopened = $engine->adoptCurrent(new AdoptCurrentCommand($identity, 'second-current', null, $released->after->state->revision, new AuditContextDTO()));

        self::assertSame(3, $reopened->after->state->revision);
        self::assertSame('second-current', $reopened->after->state->currentSlug?->value);

        $missing = $this->identity('adoption-missing');
        $this->expectException(SlugNotFoundException::class);
        $engine->adoptHistorical(new AdoptHistoricalCommand($missing, 'orphan-history', null, 0, new AuditContextDTO()));
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
            new ScopeProfileRequestDTO(new SlugScope('adoption', null, null), new SlugProfileKey('ascii-v1')),
            new EntityReference('product', $entityKey),
        );
    }

    private function scalarInt(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement);
        return (int) $statement->fetchColumn();
    }
}
