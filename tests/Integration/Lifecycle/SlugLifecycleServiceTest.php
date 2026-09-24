<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Integration\Lifecycle;

use Maatify\Slug\Lifecycle\Command\AddAliasCommand;
use Maatify\Slug\Lifecycle\Command\AssignExactCommand;
use Maatify\Slug\Lifecycle\Command\AtomicTransferCommand;
use Maatify\Slug\Lifecycle\Command\ChangeExactCommand;
use Maatify\Slug\Lifecycle\Command\ChangeGeneratedCommand;
use Maatify\Slug\Lifecycle\Command\DeactivateBindingCommand;
use Maatify\Slug\Lifecycle\Command\PromoteAliasToCurrentCommand;
use Maatify\Slug\Lifecycle\Command\PurgeBindingCommand;
use Maatify\Slug\Lifecycle\Command\ReactivateAliasCommand;
use Maatify\Slug\Lifecycle\Command\ReactivateBindingCommand;
use Maatify\Slug\Lifecycle\Command\ReleaseAllOwnershipCommand;
use Maatify\Slug\Lifecycle\Command\ReleaseClaimCommand;
use Maatify\Slug\Lifecycle\Command\RetireAliasCommand;
use Maatify\Slug\Lifecycle\Command\RestoreHistoricalCommand;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\DTO\TransferReplacementIntentDTO;
use Maatify\Slug\Lifecycle\Enum\ClaimIntentModeEnum;
use Maatify\Slug\Lifecycle\Exception\SlugCurrentClaimReleaseException;
use Maatify\Slug\Lifecycle\Exception\SlugIdempotencyConflictException;
use Maatify\Slug\Lifecycle\Exception\SlugAliasOperationNotPermittedException;
use Maatify\Slug\Lifecycle\Exception\SlugAssignmentNotPermittedException;
use Maatify\Slug\Lifecycle\Exception\SlugPurgeNotPermittedException;
use Maatify\Slug\Lifecycle\Exception\SlugReservedException;
use Maatify\Slug\Exception\SlugNotFoundException;
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

final class SlugLifecycleServiceTest extends MySqlIntegrationTestCase
{
    public function testLifecycleRolesStatusHistoryReleaseAndPurge(): void
    {
        $identity = $this->identity('product-1');
        $service = $this->service();
        $assigned = $service->assignExact(new AssignExactCommand($identity, 'Hello', null, new AuditContextDTO()));
        self::assertSame(1, $assigned->revision);
        self::assertSame('ASSIGNED', $assigned->historyEvents[0]->eventType->value);

        $changed = $service->changeExact(new ChangeExactCommand($identity, 'World', 1, new AuditContextDTO()));
        self::assertSame('world', $changed->currentSlug?->value);
        self::assertSame(2, $changed->revision);
        self::assertSame('CHANGED', $changed->historyEvents[0]->eventType->value);

        $alias = $service->addAlias(new AddAliasCommand($identity, 'Hello', 2, new AuditContextDTO()));
        self::assertSame(3, $alias->revision);
        $retired = $service->retireAlias(new RetireAliasCommand($identity, 'Hello', 3, new AuditContextDTO()));
        self::assertSame(4, $retired->revision);
        $reactivated = $service->reactivateAlias(new ReactivateAliasCommand($identity, 'Hello', 4, new AuditContextDTO()));
        self::assertSame(5, $reactivated->revision);
        $promoted = $service->promoteAliasToCurrent(new PromoteAliasToCurrentCommand($identity, 'Hello', 5, new AuditContextDTO()));
        self::assertSame('hello', $promoted->currentSlug?->value);
        self::assertSame(6, $promoted->revision);

        $restored = $service->restoreHistorical(new RestoreHistoricalCommand($identity, 'world', 6, new AuditContextDTO()));
        self::assertSame('world', $restored->currentSlug?->value);
        self::assertSame(7, $restored->revision);

        $deactivated = $service->deactivate(new DeactivateBindingCommand($identity, 7, new AuditContextDTO()));
        self::assertSame('INACTIVE', $deactivated->after->state->status->value);
        $reactivatedBinding = $service->reactivate(new ReactivateBindingCommand($identity, 8, new AuditContextDTO()));
        self::assertSame('ACTIVE', $reactivatedBinding->after->state->status->value);

        try {
            $service->releaseClaim(new ReleaseClaimCommand($identity, 'world', 9, new AuditContextDTO()));
            self::fail('Current claim release did not fail.');
        } catch (SlugCurrentClaimReleaseException) {
            self::assertSame(9, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'product-1']));
        }

        $released = $service->releaseAllOwnership(new ReleaseAllOwnershipCommand($identity, 9, new AuditContextDTO()));
        self::assertSame('RELEASED', $released->after->state->status->value);
        self::assertSame([], $released->affectedClaims);
        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry'));
        self::assertSame(12, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_history WHERE binding_id = :binding_id', ['binding_id' => $released->after->id]));
        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_operations'));

        $service->purgeBinding(new PurgeBindingCommand($identity, 10));
        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_bindings'));
        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_history'));
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_scopes'));
    }

    public function testKeyedMutationReplaysImmutableSnapshot(): void
    {
        $identity = $this->identity('idempotent');
        $audit = new AuditContextDTO(idempotencyKey: 'lifecycle-key');
        $service = $this->service();
        $first = $service->assignExact(new AssignExactCommand($identity, 'Replay', null, $audit));
        $replay = $service->assignExact(new AssignExactCommand($identity, 'Replay', null, $audit));
        self::assertFalse($first->replayed);
        self::assertTrue($replay->replayed);
        self::assertSame($first->operationKey, $replay->operationKey);
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_operations'));
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_history'));
    }

    public function testAssignmentStateMatrixAndNaturalNoOpKeepRevisionAndHistoryStable(): void
    {
        $service = $this->service();
        $identity = $this->identity('assignment-state');
        $assigned = $service->assignExact(new AssignExactCommand($identity, 'assignment-first', null, new AuditContextDTO()));
        self::assertSame(1, $assigned->revision);
        $historyAfterAssign = $this->scalarInt('SELECT COUNT(*) FROM maa_slug_history');

        try {
            $service->assignExact(new AssignExactCommand($identity, 'assignment-second', 1, new AuditContextDTO()));
            self::fail('Assignment on ACTIVE Binding did not fail.');
        } catch (SlugAssignmentNotPermittedException) {
            self::assertSame(1, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'assignment-state']));
        }
        $released = $service->releaseAllOwnership(new ReleaseAllOwnershipCommand($identity, 1, new AuditContextDTO()));
        $reopened = $service->assignExact(new AssignExactCommand($identity, 'assignment-second', $released->after->state->revision, new AuditContextDTO()));
        self::assertSame(3, $reopened->revision);
        try {
            $service->assignExact(new AssignExactCommand($identity, 'assignment-third', null, new AuditContextDTO()));
            self::fail('Assignment on RELEASED Binding with NULL revision did not fail.');
        } catch (SlugRevisionConflictException) {
            self::assertSame(3, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'assignment-state']));
        }

        $inactive = $service->deactivate(new DeactivateBindingCommand($identity, 3, new AuditContextDTO()));
        self::assertSame(4, $inactive->revision);
        try {
            $service->assignExact(new AssignExactCommand($identity, 'assignment-third', 4, new AuditContextDTO()));
            self::fail('Assignment on INACTIVE Binding did not fail.');
        } catch (SlugAssignmentNotPermittedException) {
            self::assertSame(4, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'assignment-state']));
        }

        $noop = $service->changeExact(new ChangeExactCommand($identity, 'assignment-second', 4, new AuditContextDTO()));
        self::assertSame(4, $noop->revision);
        self::assertSame([], $noop->historyEvents);
        self::assertSame($historyAfterAssign + 4, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_history'));
        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_operations'));
    }

    public function testAliasMatrixCoversNoOpAndRepeatedOrRetiredRejections(): void
    {
        $service = $this->service();
        $identity = $this->identity('alias-matrix');
        $service->assignExact(new AssignExactCommand($identity, 'alias-main', null, new AuditContextDTO()));
        $added = $service->addAlias(new AddAliasCommand($identity, 'alias-value', 1, new AuditContextDTO()));
        self::assertSame(2, $added->revision);
        $noop = $service->addAlias(new AddAliasCommand($identity, 'alias-value', 2, new AuditContextDTO()));
        self::assertSame(2, $noop->revision);
        self::assertSame([], $noop->historyEvents);
        $retired = $service->retireAlias(new RetireAliasCommand($identity, 'alias-value', 2, new AuditContextDTO()));
        self::assertSame(3, $retired->revision);
        try {
            $service->retireAlias(new RetireAliasCommand($identity, 'alias-value', 3, new AuditContextDTO()));
            self::fail('Repeated alias retirement did not fail.');
        } catch (SlugAliasOperationNotPermittedException) {
            self::assertSame(3, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'alias-matrix']));
        }
        try {
            $service->promoteAliasToCurrent(new PromoteAliasToCurrentCommand($identity, 'alias-value', 3, new AuditContextDTO()));
            self::fail('Retired alias promotion did not fail.');
        } catch (SlugAliasOperationNotPermittedException) {
            self::assertSame(3, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'alias-matrix']));
        }
        $reactivated = $service->reactivateAlias(new ReactivateAliasCommand($identity, 'alias-value', 3, new AuditContextDTO()));
        self::assertSame(4, $reactivated->revision);
        $promoted = $service->promoteAliasToCurrent(new PromoteAliasToCurrentCommand($identity, 'alias-value', 4, new AuditContextDTO()));
        self::assertSame(5, $promoted->revision);
        self::assertSame('alias-value', $promoted->currentSlug?->value);
    }

    public function testGeneratedAllocationSkipsReservedAndOtherOwnershipAndChangeRestoresHistorical(): void
    {
        $service = $this->service(['product', 'product-2']);
        $generated = $service->assignGenerated(new \Maatify\Slug\Lifecycle\Command\AssignGeneratedCommand($this->identity('generated'), 'Product', null, new AuditContextDTO()));
        self::assertSame('product-3', $generated->currentSlug?->value);

        $service->assignExact(new AssignExactCommand($this->identity('owner'), 'taken', null, new AuditContextDTO()));
        $generatedCollision = $service->assignGenerated(new \Maatify\Slug\Lifecycle\Command\AssignGeneratedCommand($this->identity('generated-2'), 'Taken', null, new AuditContextDTO()));
        self::assertSame('taken-2', $generatedCollision->currentSlug?->value);

        $identity = $this->identity('restore');
        $service->assignExact(new AssignExactCommand($identity, 'first', null, new AuditContextDTO()));
        $service->changeExact(new ChangeExactCommand($identity, 'second', 1, new AuditContextDTO()));
        $restored = $service->changeGenerated(new ChangeGeneratedCommand($identity, 'first', 2, new AuditContextDTO()));
        self::assertSame('first', $restored->currentSlug?->value);
        self::assertSame('RESTORED', $restored->changeType->value);
        $noop = $service->changeGenerated(new ChangeGeneratedCommand($identity, 'first', 3, new AuditContextDTO()));
        self::assertSame([], $noop->historyEvents);
        self::assertSame(3, $noop->revision);
    }

    public function testIdempotencyFingerprintConflictsBeforeSecondMutation(): void
    {
        $identity = $this->identity('conflict');
        $audit = new AuditContextDTO(idempotencyKey: 'same-key');
        $service = $this->service();
        $service->assignExact(new AssignExactCommand($identity, 'first', null, $audit));
        try {
            $service->assignExact(new AssignExactCommand($identity, 'different', null, $audit));
            self::fail('A changed request reused an idempotency key.');
        } catch (SlugIdempotencyConflictException) {
            self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_history'));
            self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_operations'));
        }
    }

    public function testPurgeRejectsLiveBinding(): void
    {
        $identity = $this->identity('live');
        $service = $this->service();
        $service->assignExact(new AssignExactCommand($identity, 'live', null, new AuditContextDTO()));
        try {
            $service->purgeBinding(new PurgeBindingCommand($identity, 1));
            self::fail('Purge of live Binding did not fail.');
        } catch (SlugPurgeNotPermittedException) {
            self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_bindings'));
        }
    }

    public function testPurgeRemovesLastParticipantAndRetainsSharedTransferOperationUntilLastParticipant(): void
    {
        $service = $this->service();
        $single = $this->identity('purge-single');
        $singleAudit = new AuditContextDTO(idempotencyKey: 'purge-single-operation');
        $service->assignExact(new AssignExactCommand($single, 'purge-single-slug', null, $singleAudit));
        $singleReleased = $service->releaseAllOwnership(new ReleaseAllOwnershipCommand($single, 1, new AuditContextDTO()));
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_operations'));
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_operation_bindings'));

        $service->purgeBinding(new PurgeBindingCommand($single, $singleReleased->after->state->revision));
        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'purge-single']));
        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_history WHERE entity_key_snapshot = :entity_key', ['entity_key' => 'purge-single']));
        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_operation_bindings'));
        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_operations'));

        $source = $this->identity('purge-shared-source');
        $target = $this->identity('purge-shared-target');
        $service->assignExact(new AssignExactCommand($source, 'purge-shared-source-slug', null, new AuditContextDTO()));
        $service->assignExact(new AssignExactCommand($target, 'purge-shared-target-slug', null, new AuditContextDTO()));
        $targetReleased = $service->releaseAllOwnership(new ReleaseAllOwnershipCommand($target, 1, new AuditContextDTO()));
        $transfer = $service->atomicTransfer(new AtomicTransferCommand(
            $source,
            $target,
            'purge-shared-source-slug',
            new TransferReplacementIntentDTO(ClaimIntentModeEnum::EXACT, 'purge-shared-replacement'),
            1,
            $targetReleased->after->state->revision,
            new AuditContextDTO(idempotencyKey: 'purge-shared-transfer'),
        ));
        $operationKey = $transfer->operationKey;
        self::assertNotNull($operationKey);
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_operations WHERE operation_key = :operation_key', ['operation_key' => $operationKey]));
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_operation_bindings WHERE operation_id = (SELECT id FROM maa_slug_operations WHERE operation_key = :operation_key)', ['operation_key' => $operationKey]));

        $sourceReleased = $service->releaseAllOwnership(new ReleaseAllOwnershipCommand($source, $transfer->sourceRevision, new AuditContextDTO()));
        $service->purgeBinding(new PurgeBindingCommand($source, $sourceReleased->after->state->revision));
        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_operation_bindings WHERE binding_id = (SELECT id FROM maa_slug_bindings WHERE entity_key = :entity_key)', ['entity_key' => 'purge-shared-source']));
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_operation_bindings WHERE operation_id = (SELECT id FROM maa_slug_operations WHERE operation_key = :operation_key)', ['operation_key' => $operationKey]));
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_operations WHERE operation_key = :operation_key AND result_snapshot IS NOT NULL', ['operation_key' => $operationKey]));

        $targetReleasedAgain = $service->releaseAllOwnership(new ReleaseAllOwnershipCommand($target, $transfer->targetRevision, new AuditContextDTO()));
        $service->purgeBinding(new PurgeBindingCommand($target, $targetReleasedAgain->after->state->revision));
        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_operations WHERE operation_key = :operation_key', ['operation_key' => $operationKey]));
    }

    public function testTransferRejectsAbsentAndStaleParticipantsWithoutMutation(): void
    {
        $service = $this->service();
        $source = $this->identity('precondition-source');
        $absentTarget = $this->identity('precondition-absent-target');
        $service->assignExact(new AssignExactCommand($source, 'precondition-source-slug', null, new AuditContextDTO()));

        try {
            $service->atomicTransfer(new AtomicTransferCommand(
                $source,
                $absentTarget,
                'precondition-source-slug',
                new TransferReplacementIntentDTO(ClaimIntentModeEnum::EXACT, 'precondition-replacement'),
                1,
                1,
                new AuditContextDTO(),
            ));
            self::fail('Transfer to an absent target Binding did not fail.');
        } catch (SlugNotFoundException) {
            self::assertSame(1, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'precondition-source']));
            self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE slug = :slug', ['slug' => 'precondition-source-slug']));
            self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'precondition-absent-target']));
        }

        $target = $this->identity('precondition-target');
        $service->assignExact(new AssignExactCommand($target, 'precondition-target-slug', null, new AuditContextDTO()));
        $released = $service->releaseAllOwnership(new ReleaseAllOwnershipCommand($target, 1, new AuditContextDTO()));
        try {
            $service->atomicTransfer(new AtomicTransferCommand(
                $source,
                $target,
                'precondition-source-slug',
                new TransferReplacementIntentDTO(ClaimIntentModeEnum::EXACT, 'precondition-replacement'),
                0,
                $released->after->state->revision,
                new AuditContextDTO(),
            ));
            self::fail('Transfer with a stale source revision did not fail.');
        } catch (SlugRevisionConflictException) {
            self::assertSame(1, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'precondition-source']));
            self::assertSame(2, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'precondition-target']));
        }
        try {
            $service->atomicTransfer(new AtomicTransferCommand(
                $source,
                $target,
                'precondition-source-slug',
                new TransferReplacementIntentDTO(ClaimIntentModeEnum::EXACT, 'precondition-replacement'),
                1,
                1,
                new AuditContextDTO(),
            ));
            self::fail('Transfer with a stale target revision did not fail.');
        } catch (SlugRevisionConflictException) {
            self::assertSame(1, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'precondition-source']));
            self::assertSame(2, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'precondition-target']));
            self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE slug = :slug', ['slug' => 'precondition-source-slug']));
        }
    }

    public function testReleaseClaimMakesNonCurrentSlugReusableByAnotherBinding(): void
    {
        $service = $this->service();
        $owner = $this->identity('release-owner');
        $replacementOwner = $this->identity('release-reuser');
        $service->assignExact(new AssignExactCommand($owner, 'release-current', null, new AuditContextDTO()));
        $service->addAlias(new AddAliasCommand($owner, 'reusable-alias', 1, new AuditContextDTO()));
        $released = $service->releaseClaim(new ReleaseClaimCommand($owner, 'reusable-alias', 2, new AuditContextDTO()));
        self::assertSame(3, $released->revision);
        $reused = $service->assignExact(new AssignExactCommand($replacementOwner, 'reusable-alias', null, new AuditContextDTO()));
        self::assertSame('reusable-alias', $reused->currentSlug?->value);
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE slug = :slug', ['slug' => 'reusable-alias']));
    }

    public function testFailedAssignmentsAndTransfersRollbackAllPackageRows(): void
    {
        $reservedService = $this->service(['reserved']);
        $reservedIdentity = $this->identity('reserved-rollback');
        try {
            $reservedService->assignExact(new AssignExactCommand($reservedIdentity, 'reserved', null, new AuditContextDTO()));
            self::fail('Reserved lifecycle assignment did not fail.');
        } catch (SlugReservedException) {
            self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_scopes'));
            self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_bindings'));
            self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry'));
            self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_history'));
            self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_operations'));
        }

        $service = $this->service();
        $source = $this->identity('rollback-source');
        $target = $this->identity('rollback-target');
        $service->assignExact(new AssignExactCommand($source, 'rollback-current', null, new AuditContextDTO()));
        $service->assignExact(new AssignExactCommand($target, 'rollback-target', null, new AuditContextDTO()));
        $service->releaseAllOwnership(new ReleaseAllOwnershipCommand($target, 1, new AuditContextDTO()));
        try {
            $service->atomicTransfer(new AtomicTransferCommand(
                $source,
                $target,
                'rollback-current',
                new TransferReplacementIntentDTO(ClaimIntentModeEnum::EXACT, 'rollback-current'),
                1,
                2,
                new AuditContextDTO(),
            ));
            self::fail('Self-equal exact replacement did not fail.');
        } catch (\Maatify\Slug\Lifecycle\Exception\SlugTransferReplacementConflictException) {
            self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE slug = :slug', ['slug' => 'rollback-current']));
            self::assertSame(1, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'rollback-source']));
            self::assertSame(2, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'rollback-target']));
        }
    }

    public function testGeneratedTransferReplacementExcludesTheMovedSlug(): void
    {
        $service = $this->service();
        $source = $this->identity('generated-transfer-source');
        $target = $this->identity('generated-transfer-target');
        $service->assignExact(new AssignExactCommand($source, 'generated-move', null, new AuditContextDTO()));
        $service->assignExact(new AssignExactCommand($target, 'generated-target', null, new AuditContextDTO()));
        $service->releaseAllOwnership(new ReleaseAllOwnershipCommand($target, 1, new AuditContextDTO()));

        $transfer = $service->atomicTransfer(new AtomicTransferCommand(
            $source,
            $target,
            'generated-move',
            new TransferReplacementIntentDTO(ClaimIntentModeEnum::GENERATED, 'generated-move'),
            1,
            2,
            new AuditContextDTO(),
        ));
        self::assertSame('generated-move-2', $transfer->sourceResult->after->state->currentSlug?->value);
        self::assertSame('generated-move', $transfer->transferredClaim->slug->value);
        self::assertSame('CHANGED', $transfer->sourceReplacementResult?->changeType->value);
    }

    public function testCurrentTransferRejectsActiveAndRetiredAliasReplacements(): void
    {
        $service = $this->service();
        $activeSource = $this->identity('active-alias-replacement-source');
        $activeTarget = $this->identity('active-alias-replacement-target');
        $service->assignExact(new AssignExactCommand($activeSource, 'active-replacement-source', null, new AuditContextDTO()));
        $service->addAlias(new AddAliasCommand($activeSource, 'active-replacement-alias', 1, new AuditContextDTO()));
        $service->assignExact(new AssignExactCommand($activeTarget, 'active-replacement-target', null, new AuditContextDTO()));
        $service->releaseAllOwnership(new ReleaseAllOwnershipCommand($activeTarget, 1, new AuditContextDTO()));
        try {
            $service->atomicTransfer(new AtomicTransferCommand(
                $activeSource,
                $activeTarget,
                'active-replacement-source',
                new TransferReplacementIntentDTO(ClaimIntentModeEnum::EXACT, 'active-replacement-alias'),
                2,
                2,
                new AuditContextDTO(),
            ));
            self::fail('An ACTIVE_ALIAS replacement did not fail.');
        } catch (SlugAliasOperationNotPermittedException) {
            self::assertSame(2, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'active-alias-replacement-source']));
        }

        $retiredSource = $this->identity('retired-alias-replacement-source');
        $retiredTarget = $this->identity('retired-alias-replacement-target');
        $service->assignExact(new AssignExactCommand($retiredSource, 'retired-replacement-source', null, new AuditContextDTO()));
        $service->addAlias(new AddAliasCommand($retiredSource, 'retired-replacement-alias', 1, new AuditContextDTO()));
        $service->retireAlias(new RetireAliasCommand($retiredSource, 'retired-replacement-alias', 2, new AuditContextDTO()));
        $service->assignExact(new AssignExactCommand($retiredTarget, 'retired-replacement-target', null, new AuditContextDTO()));
        $service->releaseAllOwnership(new ReleaseAllOwnershipCommand($retiredTarget, 1, new AuditContextDTO()));
        try {
            $service->atomicTransfer(new AtomicTransferCommand(
                $retiredSource,
                $retiredTarget,
                'retired-replacement-source',
                new TransferReplacementIntentDTO(ClaimIntentModeEnum::EXACT, 'retired-replacement-alias'),
                3,
                2,
                new AuditContextDTO(),
            ));
            self::fail('A RETIRED_ALIAS replacement did not fail.');
        } catch (SlugAliasOperationNotPermittedException) {
            self::assertSame(3, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'retired-alias-replacement-source']));
        }
    }

    public function testTargetReservationRejectsCurrentAndNonCurrentTransfersBeforeMutation(): void
    {
        $service = $this->service();
        $reservedCurrentSource = $this->identity('reserved-current-source');
        $reservedCurrentTarget = $this->identity('reserved-current-target');
        $service->assignExact(new AssignExactCommand($reservedCurrentSource, 'reserved-current-slug', null, new AuditContextDTO()));
        $service->assignExact(new AssignExactCommand($reservedCurrentTarget, 'reserved-current-target', null, new AuditContextDTO()));
        $service->releaseAllOwnership(new ReleaseAllOwnershipCommand($reservedCurrentTarget, 1, new AuditContextDTO()));
        $historyBeforeCurrent = $this->scalarInt('SELECT COUNT(*) FROM maa_slug_history');

        try {
            $this->service(['reserved-current-slug'])->atomicTransfer(new AtomicTransferCommand(
                $reservedCurrentSource,
                $reservedCurrentTarget,
                'reserved-current-slug',
                new TransferReplacementIntentDTO(ClaimIntentModeEnum::EXACT, 'reserved-current-replacement'),
                1,
                2,
                new AuditContextDTO(),
            ));
            self::fail('Target reservation did not reject a current transfer.');
        } catch (SlugReservedException) {
            self::assertSame(1, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'reserved-current-source']));
            self::assertSame(2, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'reserved-current-target']));
            self::assertSame($historyBeforeCurrent, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_history'));
            self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE slug = :slug', ['slug' => 'reserved-current-slug']));
            self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_operations'));
        }

        $reservedAliasSource = $this->identity('reserved-alias-source');
        $reservedAliasTarget = $this->identity('reserved-alias-target');
        $service->assignExact(new AssignExactCommand($reservedAliasSource, 'reserved-alias-current', null, new AuditContextDTO()));
        $service->addAlias(new AddAliasCommand($reservedAliasSource, 'reserved-alias-slug', 1, new AuditContextDTO()));
        $service->assignExact(new AssignExactCommand($reservedAliasTarget, 'reserved-alias-target', null, new AuditContextDTO()));
        $historyBeforeAlias = $this->scalarInt('SELECT COUNT(*) FROM maa_slug_history');

        try {
            $this->service(['reserved-alias-slug'])->atomicTransfer(new AtomicTransferCommand(
                $reservedAliasSource,
                $reservedAliasTarget,
                'reserved-alias-slug',
                null,
                2,
                1,
                new AuditContextDTO(),
            ));
            self::fail('Target reservation did not reject a non-current transfer.');
        } catch (SlugReservedException) {
            self::assertSame(2, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'reserved-alias-source']));
            self::assertSame(1, $this->scalarInt('SELECT revision FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'reserved-alias-target']));
            self::assertSame($historyBeforeAlias, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_history'));
            self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE slug = :slug', ['slug' => 'reserved-alias-slug']));
            self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_operations'));
        }
    }

    public function testCurrentAndNonCurrentTransfersPreserveRolesAndHistory(): void
    {
        $service = $this->service();
        $source = $this->identity('source');
        $target = $this->identity('target');
        $service->assignExact(new AssignExactCommand($source, 'current', null, new AuditContextDTO()));
        $service->assignExact(new AssignExactCommand($target, 'target', null, new AuditContextDTO()));
        $service->releaseAllOwnership(new ReleaseAllOwnershipCommand($target, 1, new AuditContextDTO()));

        $transfer = $service->atomicTransfer(new AtomicTransferCommand(
            $source,
            $target,
            'current',
            new TransferReplacementIntentDTO(ClaimIntentModeEnum::EXACT, 'replacement'),
            1,
            2,
            new AuditContextDTO(),
        ));
        self::assertSame('replacement', $transfer->sourceResult->after->state->currentSlug?->value);
        self::assertSame('current', $transfer->targetResult->after->state->currentSlug?->value);
        self::assertSame('CURRENT_CANONICAL', $transfer->transferredClaim->role->value);
        if ($transfer->sourceReplacementResult === null) {
            self::fail('Current transfer did not include its source replacement result.');
        }
        self::assertSame('ATOMIC_TRANSFER', $transfer->sourceReplacementResult->operationType->value);
        self::assertSame(['CHANGED', 'OWNERSHIP_TRANSFERRED_OUT'], array_map(static fn($event): string => $event->eventType->value, $transfer->sourceResult->historyEvents));
        self::assertSame('OWNERSHIP_TRANSFERRED_IN', $transfer->targetResult->historyEvents[0]->eventType->value);

        $sourceAlias = $this->identity('source-alias');
        $targetActive = $this->identity('target-active');
        $service->assignExact(new AssignExactCommand($sourceAlias, 'source-main', null, new AuditContextDTO()));
        $service->addAlias(new AddAliasCommand($sourceAlias, 'movable-alias', 1, new AuditContextDTO()));
        $service->assignExact(new AssignExactCommand($targetActive, 'target-main', null, new AuditContextDTO()));
        $nonCurrent = $service->atomicTransfer(new AtomicTransferCommand(
            $sourceAlias,
            $targetActive,
            'movable-alias',
            null,
            2,
            1,
            new AuditContextDTO(),
        ));
        self::assertNull($nonCurrent->sourceReplacementResult);
        self::assertSame('ACTIVE_ALIAS', $nonCurrent->transferredClaim->role->value);
        self::assertSame('OWNERSHIP_TRANSFERRED_OUT', $nonCurrent->sourceResult->historyEvents[0]->eventType->value);
        self::assertSame(3, $nonCurrent->sourceRevision);
        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_operations'));
    }

    public function testCurrentTransferReplayUsesStoredAggregateAfterLiveStateChanges(): void
    {
        $service = $this->service();
        $source = $this->identity('replay-source');
        $target = $this->identity('replay-target');
        $service->assignExact(new AssignExactCommand($source, 'replay-current', null, new AuditContextDTO()));
        $service->assignExact(new AssignExactCommand($target, 'replay-target', null, new AuditContextDTO()));
        $service->releaseAllOwnership(new ReleaseAllOwnershipCommand($target, 1, new AuditContextDTO()));
        $audit = new AuditContextDTO(idempotencyKey: 'transfer-replay-key');
        $command = new AtomicTransferCommand($source, $target, 'replay-current', new TransferReplacementIntentDTO(ClaimIntentModeEnum::EXACT, 'replay-replacement'), 1, 2, $audit);
        $first = $service->atomicTransfer($command);
        $operationKey = $first->operationKey;
        self::assertNotNull($operationKey);
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_operations WHERE operation_key = :operation_key AND result_snapshot IS NOT NULL', ['operation_key' => $operationKey]));
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_operation_bindings WHERE operation_id = (SELECT id FROM maa_slug_operations WHERE operation_key = :operation_key) AND participant_role = \'SOURCE\'', ['operation_key' => $operationKey]));
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_operation_bindings WHERE operation_id = (SELECT id FROM maa_slug_operations WHERE operation_key = :operation_key) AND participant_role = \'TARGET\'', ['operation_key' => $operationKey]));
        self::assertSame($operationKey, $first->sourceReplacementResult?->operationKey);
        self::assertSame([$operationKey], array_values(array_unique(array_map(static fn($event): ?string => $event->operationKey, $first->historyEvents))));
        $historyCount = $this->scalarInt('SELECT COUNT(*) FROM maa_slug_history');
        $service->releaseAllOwnership(new ReleaseAllOwnershipCommand($target, 3, new AuditContextDTO()));
        $replay = $service->atomicTransfer($command);
        self::assertTrue($replay->replayed);
        self::assertTrue($replay->sourceReplacementResult?->replayed);
        self::assertSame($operationKey, $replay->operationKey);
        self::assertSame($first->sourceRevision, $replay->sourceRevision);
        self::assertSame($first->targetRevision, $replay->targetRevision);
        self::assertSame($historyCount + 2, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_history'));
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_operations'));
    }

    public function testTransferPreservesHistoricalAndRetiredAliasRoles(): void
    {
        $service = $this->service();
        $historicalSource = $this->identity('historical-transfer-source');
        $historicalTarget = $this->identity('historical-transfer-target');
        $service->assignExact(new AssignExactCommand($historicalSource, 'historical-main', null, new AuditContextDTO()));
        $service->changeExact(new ChangeExactCommand($historicalSource, 'historical-current', 1, new AuditContextDTO()));
        $service->assignExact(new AssignExactCommand($historicalTarget, 'historical-target', null, new AuditContextDTO()));
        $historicalTransfer = $service->atomicTransfer(new AtomicTransferCommand(
            $historicalSource,
            $historicalTarget,
            'historical-main',
            null,
            2,
            1,
            new AuditContextDTO(),
        ));
        self::assertSame('HISTORICAL_CANONICAL', $historicalTransfer->transferredClaim->role->value);
        self::assertSame('historical-target', $historicalTransfer->targetResult->after->state->currentSlug?->value);
        self::assertSame('historical-current', $historicalTransfer->sourceResult->after->state->currentSlug?->value);
        self::assertSame(3, $historicalTransfer->sourceRevision);
        self::assertSame(2, $historicalTransfer->targetRevision);
        self::assertSame('HISTORICAL_CANONICAL', $historicalTransfer->sourceResult->historyEvents[0]->claimRoleSnapshot?->value);

        $retiredSource = $this->identity('retired-transfer-source');
        $retiredTarget = $this->identity('retired-transfer-target');
        $service->assignExact(new AssignExactCommand($retiredSource, 'retired-main', null, new AuditContextDTO()));
        $service->addAlias(new AddAliasCommand($retiredSource, 'retired-alias', 1, new AuditContextDTO()));
        $service->retireAlias(new RetireAliasCommand($retiredSource, 'retired-alias', 2, new AuditContextDTO()));
        $service->assignExact(new AssignExactCommand($retiredTarget, 'retired-target', null, new AuditContextDTO()));
        $retiredTransfer = $service->atomicTransfer(new AtomicTransferCommand(
            $retiredSource,
            $retiredTarget,
            'retired-alias',
            null,
            3,
            1,
            new AuditContextDTO(),
        ));
        self::assertSame('RETIRED_ALIAS', $retiredTransfer->transferredClaim->role->value);
        self::assertSame('RETIRED_ALIAS', $retiredTransfer->targetResult->historyEvents[0]->claimRoleSnapshot?->value);
        self::assertSame(4, $retiredTransfer->sourceRevision);
        self::assertSame(2, $retiredTransfer->targetRevision);
        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_operations'));
    }

    public function testCurrentTransferRestoresHistoricalReplacementBeforeMovingClaim(): void
    {
        $service = $this->service();
        $source = $this->identity('restore-transfer-source');
        $target = $this->identity('restore-transfer-target');
        $service->assignExact(new AssignExactCommand($source, 'restore-old', null, new AuditContextDTO()));
        $service->changeExact(new ChangeExactCommand($source, 'restore-current', 1, new AuditContextDTO()));
        $service->assignExact(new AssignExactCommand($target, 'restore-target', null, new AuditContextDTO()));
        $service->releaseAllOwnership(new ReleaseAllOwnershipCommand($target, 1, new AuditContextDTO()));

        $transfer = $service->atomicTransfer(new AtomicTransferCommand(
            $source,
            $target,
            'restore-current',
            new TransferReplacementIntentDTO(ClaimIntentModeEnum::EXACT, 'restore-old'),
            2,
            2,
            new AuditContextDTO(),
        ));
        if ($transfer->sourceReplacementResult === null) {
            self::fail('Historical replacement was not returned.');
        }
        self::assertSame('RESTORED', $transfer->sourceReplacementResult->changeType->value);
        self::assertSame('restore-old', $transfer->sourceResult->after->state->currentSlug?->value);
        self::assertSame(['RESTORED', 'OWNERSHIP_TRANSFERRED_OUT'], array_map(static fn($event): string => $event->eventType->value, $transfer->sourceResult->historyEvents));
        self::assertSame('CURRENT_CANONICAL', $transfer->transferredClaim->role->value);
    }

    /** @param list<string> $reserved */
    private function service(array $reserved = []): SlugLifecycleService
    {
        $profiles = new SlugProfileRegistry();
        $profiles->register(new TestSlugProfile());
        return SlugLifecycleServiceFactory::create($this->pdo, $profiles, new TestReservedSlugPolicy($reserved), $this->clock());
    }

    private function identity(string $entityKey): BindingIdentityDTO
    {
        return new BindingIdentityDTO(
            new ScopeProfileRequestDTO(new SlugScope('catalog', null, null), new SlugProfileKey('ascii-v1')),
            new EntityReference('product', $entityKey),
        );
    }

    /**
     * @phpstan-impure
     * @param array<string, scalar|null> $parameters
     */
    private function scalarInt(string $sql, array $parameters = []): int
    {
        $statement = $this->pdo->prepare($sql);
        self::assertNotFalse($statement);
        $statement->execute($parameters);
        return (int) $statement->fetchColumn();
    }
}
