<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Integration\Adoption;

use DateTimeImmutable;
use Maatify\Slug\Lifecycle\Command\AdoptAliasCommand;
use Maatify\Slug\Lifecycle\Command\AdoptCurrentCommand;
use Maatify\Slug\Lifecycle\Command\AdoptHistoricalCommand;
use Maatify\Slug\Lifecycle\Command\ReleaseAllOwnershipCommand;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Registry\DTO\BindingIdentityDTO;
use Maatify\Slug\Scope\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Engine\SlugEngine;
use Maatify\Slug\Engine\SlugEngineFactory;
use Maatify\Slug\Exception\SlugAlreadyClaimedException;
use Maatify\Slug\Exception\SlugIdempotencyConflictException;
use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\Exception\SlugNotFoundException;
use Maatify\Slug\Exception\SlugRevisionConflictException;
use Maatify\Slug\Registry\Value\EntityReference;
use Maatify\Slug\Profile\Value\SlugProfileKey;
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
            'imported-current',
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
            'imported-current',
            $original,
            null,
            new AuditContextDTO(idempotencyKey: 'adopt-current-key'),
        ));
        self::assertTrue($replay->replayed);
        self::assertSame($current->operationKey, $replay->operationKey);
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_history'));

        $historical = $engine->adoptHistorical(new AdoptHistoricalCommand(
            $identity,
            'imported-historical',
            null,
            1,
            new AuditContextDTO(),
        ));
        self::assertSame(2, $historical->after->state->revision);
        self::assertSame('HISTORICAL_CANONICAL', $historical->adoptedClaim->role->value);
        self::assertSame(2, $historical->historyEvent->sequenceNo);

        $alias = $engine->adoptAlias(new AdoptAliasCommand(
            $identity,
            'imported-alias',
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

    public function testAdoptionTimestampMatrixUsesUtcMicrosAndDoesNotMutateDefaultTimezone(): void
    {
        $engine = $this->engine();
        $timezoneBefore = date_default_timezone_get();
        $cases = [
            'utc' => new DateTimeImmutable('2025-01-02T03:04:05.123456Z'),
            'positive-offset' => new DateTimeImmutable('2025-01-02T03:04:05.123456+05:30'),
            'negative-offset' => new DateTimeImmutable('2025-01-02T03:04:05.123456-07:00'),
            'minimum' => new DateTimeImmutable('1000-01-01T00:00:00.000000Z'),
            'maximum' => new DateTimeImmutable('9999-12-31T23:59:59.999999Z'),
            'future' => new DateTimeImmutable('2999-12-31T23:59:59.999999+03:00'),
            'old' => new DateTimeImmutable('1001-01-01T00:00:00.000001-04:00'),
        ];

        foreach ($cases as $name => $original) {
            $result = $engine->adoptCurrent(new AdoptCurrentCommand(
                $this->identity('timestamp-' . $name),
                'timestamp-' . $name,
                $original,
                null,
                new AuditContextDTO(),
            ));

            self::assertSame(
                $original->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
                $result->historyEvent->originalOccurredAt?->format('Y-m-d\TH:i:s.u\Z'),
            );
            self::assertSame('2026-01-01T00:00:00.123456Z', $result->historyEvent->occurredAt->format('Y-m-d\TH:i:s.u\Z'));
        }

        $nullOriginal = $engine->adoptCurrent(new AdoptCurrentCommand(
            $this->identity('timestamp-null'),
            'timestamp-null',
            null,
            null,
            new AuditContextDTO(),
        ));
        self::assertSame('2026-01-01T00:00:00.123456Z', $nullOriginal->historyEvent->originalOccurredAt?->format('Y-m-d\TH:i:s.u\Z'));
        self::assertSame($timezoneBefore, date_default_timezone_get());
    }

    public function testAdoptionRejectsUtcOutOfRangeTimestampsBeforePersistence(): void
    {
        $engine = $this->engine();
        $below = new DateTimeImmutable('1000-01-01T00:00:00.000000+01:00');
        $above = new DateTimeImmutable('9999-12-31T23:59:59.999999-01:00');

        try {
            new AdoptCurrentCommand($this->identity('timestamp-below'), 'timestamp-below', $below, null, new AuditContextDTO());
            self::fail('A timestamp below the supported UTC range was accepted.');
        } catch (SlugInvalidArgumentException $exception) {
            self::assertSame(SlugInvalidArgumentException::class, $exception::class);
        }
        try {
            new AdoptCurrentCommand($this->identity('timestamp-above'), 'timestamp-above', $above, null, new AuditContextDTO());
            self::fail('A timestamp above the supported UTC range was accepted.');
        } catch (SlugInvalidArgumentException $exception) {
            self::assertSame(SlugInvalidArgumentException::class, $exception::class);
        }
        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_scopes'));
        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry'));
    }

    public function testAdoptionSnapshotsReplayImmutablyAndIdempotencySeparatesSemanticCompetition(): void
    {
        $engine = $this->engine();
        $identity = $this->identity('adoption-snapshot');
        $command = new AdoptCurrentCommand($identity, 'snapshot-current', null, null, new AuditContextDTO(idempotencyKey: 'adoption-snapshot-key'));
        $first = $engine->adoptCurrent($command);
        $snapshotBefore = $this->operationColumn('adoption-snapshot-key', 'result_snapshot');
        self::assertSame('ADOPT_CURRENT', $this->operationColumn('adoption-snapshot-key', 'operation_type'));
        self::assertSame('adoption', $this->operationColumn('adoption-snapshot-key', 'result_type'));
        self::assertSame('1', $this->operationColumn('adoption-snapshot-key', 'result_schema_version'));
        self::assertNotSame('', $snapshotBefore);

        $engine->releaseAllOwnership(new ReleaseAllOwnershipCommand($identity, 1, new AuditContextDTO()));
        $replay = $engine->adoptCurrent($command);
        self::assertTrue($replay->replayed);
        self::assertSame($first->after->state->status->value, $replay->after->state->status->value);
        self::assertSame($snapshotBefore, $this->operationColumn('adoption-snapshot-key', 'result_snapshot'));

        $this->expectException(SlugIdempotencyConflictException::class);
        $engine->adoptCurrent(new AdoptCurrentCommand(
            $identity,
            'different-snapshot-current',
            null,
            null,
            new AuditContextDTO(idempotencyKey: 'adoption-snapshot-key'),
        ));
    }

    public function testHistoricalAndAliasStaleRevisionsAndDifferentKeysRespectClaimCompetition(): void
    {
        $engine = $this->engine();
        $identity = $this->identity('adoption-stale');
        $engine->adoptCurrent(new AdoptCurrentCommand($identity, 'stale-current', null, null, new AuditContextDTO()));

        try {
            $engine->adoptHistorical(new AdoptHistoricalCommand($identity, 'stale-history', null, 0, new AuditContextDTO()));
            self::fail('A stale historical revision was accepted.');
        } catch (SlugRevisionConflictException $exception) {
            self::assertSame(SlugRevisionConflictException::class, $exception::class);
        }
        try {
            $engine->adoptAlias(new AdoptAliasCommand($identity, 'stale-alias', null, 0, new AuditContextDTO()));
            self::fail('A stale alias revision was accepted.');
        } catch (SlugRevisionConflictException $exception) {
            self::assertSame(SlugRevisionConflictException::class, $exception::class);
        }

        $first = $this->identity('adoption-competition-first');
        $second = $this->identity('adoption-competition-second');
        $engine->adoptCurrent(new AdoptCurrentCommand($first, 'competition-current', null, null, new AuditContextDTO()));
        $engine->adoptCurrent(new AdoptCurrentCommand($second, 'competition-second', null, null, new AuditContextDTO()));
        $engine->adoptHistorical(new AdoptHistoricalCommand($first, 'competition-history', null, 1, new AuditContextDTO(idempotencyKey: 'competition-first')));

        $this->expectException(SlugAlreadyClaimedException::class);
        $engine->adoptHistorical(new AdoptHistoricalCommand($second, 'competition-history', null, 1, new AuditContextDTO(idempotencyKey: 'competition-second')));
    }

    public function testReleasedCurrentAdoptionRequiresExplicitRevision(): void
    {
        $engine = $this->engine();
        $identity = $this->identity('adoption-released-revision');
        $engine->adoptCurrent(new AdoptCurrentCommand($identity, 'released-current', null, null, new AuditContextDTO()));
        $engine->releaseAllOwnership(new ReleaseAllOwnershipCommand($identity, 1, new AuditContextDTO()));

        $this->expectException(SlugRevisionConflictException::class);
        $engine->adoptCurrent(new AdoptCurrentCommand($identity, 'released-reopen-without-revision', null, null, new AuditContextDTO()));
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
