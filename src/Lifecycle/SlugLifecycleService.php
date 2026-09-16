<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle;

use JsonException;
use PDO;
use PDOException;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Slug\Allocation\GeneratedCandidateSequence;
use Maatify\Slug\Command\AddAliasCommand;
use Maatify\Slug\Command\AssignExactCommand;
use Maatify\Slug\Command\AssignGeneratedCommand;
use Maatify\Slug\Command\AtomicTransferCommand;
use Maatify\Slug\Command\ChangeExactCommand;
use Maatify\Slug\Command\ChangeGeneratedCommand;
use Maatify\Slug\Command\DeactivateBindingCommand;
use Maatify\Slug\Command\PromoteAliasToCurrentCommand;
use Maatify\Slug\Command\PurgeBindingCommand;
use Maatify\Slug\Command\ReactivateAliasCommand;
use Maatify\Slug\Command\ReactivateBindingCommand;
use Maatify\Slug\Command\ReleaseAllOwnershipCommand;
use Maatify\Slug\Command\ReleaseClaimCommand;
use Maatify\Slug\Command\RestoreHistoricalCommand;
use Maatify\Slug\Command\RetireAliasCommand;
use Maatify\Slug\Contract\ReservedSlugPolicyInterface;
use Maatify\Slug\DTO\AuditContextDTO;
use Maatify\Slug\DTO\BindingDTO;
use Maatify\Slug\DTO\BindingIdentityDTO;
use Maatify\Slug\DTO\BindingStateResultDTO;
use Maatify\Slug\DTO\AtomicTransferResultDTO;
use Maatify\Slug\DTO\HistoryEventDTO;
use Maatify\Slug\DTO\RegistryClaimDTO;
use Maatify\Slug\DTO\SlugMutationResultDTO;
use Maatify\Slug\Enum\BindingStatusEnum;
use Maatify\Slug\Enum\ChangeTypeEnum;
use Maatify\Slug\Enum\HistoryEventTypeEnum;
use Maatify\Slug\Enum\OperationTypeEnum;
use Maatify\Slug\Enum\RegistryRoleEnum;
use Maatify\Slug\Exception\SlugAliasOperationNotPermittedException;
use Maatify\Slug\Exception\SlugAlreadyClaimedException;
use Maatify\Slug\Exception\SlugAllocationExhaustedException;
use Maatify\Slug\Exception\SlugAssignmentNotPermittedException;
use Maatify\Slug\Exception\SlugCurrentClaimReleaseException;
use Maatify\Slug\Exception\SlugHistoricalRestoreNotPermittedException;
use Maatify\Slug\Exception\SlugIdempotencyConflictException;
use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\Exception\SlugNotFoundException;
use Maatify\Slug\Exception\SlugPersistenceInvariantException;
use Maatify\Slug\Exception\SlugPurgeNotPermittedException;
use Maatify\Slug\Exception\SlugReservedException;
use Maatify\Slug\Exception\SlugRevisionConflictException;
use Maatify\Slug\Exception\SlugScopeProfileMismatchException;
use Maatify\Slug\Exception\SlugTransferReplacementConflictException;
use Maatify\Slug\History\HistoryEventDraft;
use Maatify\Slug\Identity\Slug;
use Maatify\Slug\Infrastructure\Persistence\PDO\Binding\PdoBindingMaintenanceRepository;
use Maatify\Slug\Infrastructure\Persistence\PDO\Connection\PdoCapabilityGuard;
use Maatify\Slug\Infrastructure\Persistence\PDO\Connection\PdoDuplicateClassifier;
use Maatify\Slug\Infrastructure\Persistence\PDO\History\PdoHistoryRepository;
use Maatify\Slug\Infrastructure\Persistence\PDO\Operations\PdoOperationRepository;
use Maatify\Slug\Infrastructure\Persistence\PDO\Registry\PdoRegistryRepository;
use Maatify\Slug\Infrastructure\Persistence\PDO\Registry\RegistryBindingRecord;
use Maatify\Slug\Infrastructure\Persistence\PDO\Registry\RegistryClaimRecord;
use Maatify\Slug\Infrastructure\Persistence\PDO\Scope\PdoScopeRepository;
use Maatify\Slug\Internal\ResultSnapshot\ResultSnapshotMetadata;
use Maatify\Slug\Contract\ResultSnapshot\ResultSnapshotEncoder;
use Maatify\Slug\Internal\Transaction\PdoTransactionCoordinator;
use Maatify\Slug\Persistence\Contract\OperationParticipant;
use Maatify\Slug\Persistence\Contract\OperationReservation;
use Maatify\Slug\Profile\Contracts\SlugProfileInterface;
use Maatify\Slug\Profile\Contracts\SlugProfileRegistryInterface;
use Maatify\Slug\Reserved\ReservationEvaluator;
use Maatify\Slug\Scope\Value\SlugScope;

/**
 * Concrete WU-05 lifecycle boundary.
 *
 * This class intentionally implements only the WU-05 operations. The complete
 * SlugLifecycleServiceInterface/SlugEngine wiring remains a WU-06 concern
 * because transition and adoption are not implemented here.
 */
final class SlugLifecycleService
{
    private PdoTransactionCoordinator $transactions;

    private PdoScopeRepository $scopes;

    private PdoRegistryRepository $registry;

    private PdoHistoryRepository $history;

    private PdoOperationRepository $operations;

    private PdoBindingMaintenanceRepository $maintenance;

    private PdoCapabilityGuard $capabilities;

    private ReservationEvaluator $reservations;

    private GeneratedCandidateSequence $candidates;

    public function __construct(
        private readonly PDO $pdo,
        private readonly SlugProfileRegistryInterface $profiles,
        ReservedSlugPolicyInterface $reservedPolicy,
        private readonly ClockInterface $clock,
        ?PdoCapabilityGuard $capabilities = null,
        ?PdoTransactionCoordinator $transactions = null,
        ?GeneratedCandidateSequence $candidates = null,
    ) {
        $this->capabilities = $capabilities ?? new PdoCapabilityGuard($pdo);
        $this->transactions = $transactions ?? new PdoTransactionCoordinator($pdo);
        $this->scopes = new PdoScopeRepository($pdo, $profiles, $clock, $this->capabilities, $this->transactions);
        $this->registry = new PdoRegistryRepository($pdo, $profiles, $this->scopes, $clock, $this->capabilities);
        $this->history = new PdoHistoryRepository($pdo, $profiles, $this->capabilities);
        $this->operations = new PdoOperationRepository($pdo, $clock, $this->capabilities, $this->transactions);
        $this->maintenance = new PdoBindingMaintenanceRepository($pdo, $this->capabilities);
        $this->reservations = new ReservationEvaluator($reservedPolicy);
        $this->candidates = $candidates ?? new GeneratedCandidateSequence();
    }

    public function assignExact(AssignExactCommand $command): SlugMutationResultDTO
    {
        $profile = $this->profiles->get($command->binding->scopeProfile->expectedProfileKey);
        $slug = $profile->canonicalizeClaim($command->slugCandidate)->slug;
        $fingerprint = $this->fingerprint(
            OperationTypeEnum::ASSIGN_EXACT,
            $command->binding,
            ['mode' => 'EXACT', 'value' => $slug->value],
            null,
            $command->expectedRevision,
            null,
            null,
        );

        return $this->transactions->run(function () use ($command, $slug, $fingerprint): SlugMutationResultDTO {
            $scope = $this->registry->ensureScope($command->binding->scopeProfile->scope, $command->binding->scopeProfile->expectedProfileKey);
            $lockedScope = $this->registry->lockScope($scope->scope, $scope->profileKey);
            $record = $this->registry->lockOrCreateBinding($lockedScope, $command->binding->entity);
            $before = $record->createdInCurrentTransaction ? null : $this->binding($command->binding, true);
            if ($before === null && ! $record->createdInCurrentTransaction) {
                throw new SlugPersistenceInvariantException('Existing Binding cannot be read before assignment.');
            }
            $reservation = $this->reserve(
                OperationTypeEnum::ASSIGN_EXACT,
                'mutation',
                $fingerprint,
                [$record->id],
                $command->audit,
            );
            if ($reservation !== null && ! $reservation->created) {
                return $this->replayMutation($reservation);
            }

            $this->assertAssignmentState($record, $command->expectedRevision);
            $existing = $this->registry->findClaimByScopeSlug($lockedScope->id, $slug, true);
            if ($existing !== null) {
                if ($existing->bindingId === $record->id) {
                    throw new SlugAssignmentNotPermittedException('Assignment cannot reuse a retained same-Binding role.');
                }
                throw new SlugAlreadyClaimedException('The requested slug is owned by another Binding.');
            }
            if ($this->reservations->isReserved($lockedScope->scope, $slug)) {
                throw new SlugReservedException('The requested slug is reserved.');
            }
            $claim = $this->registry->insertClaim($lockedScope->id, $record->id, $slug, RegistryRoleEnum::CURRENT_CANONICAL);
            $events = $this->appendEvents(
                $record->id,
                $before?->state->historySequence ?? 0,
                [$this->claimEvent(
                    HistoryEventTypeEnum::ASSIGNED,
                    $command->binding,
                    $slug,
                    null,
                    RegistryRoleEnum::CURRENT_CANONICAL,
                    null,
                    null,
                    null,
                    $reservation,
                    $command->audit,
                )],
            );
            $this->registry->mutateBinding($record->id, $record->revision, BindingStatusEnum::ACTIVE, $claim->id, ($before?->state->historySequence ?? 0) + 1);
            $after = $this->requiredBinding($command->binding, true);

            return $this->finishMutation(
                OperationTypeEnum::ASSIGN_EXACT,
                $reservation,
                $before,
                $after,
                $this->claimsDto($record->id, true),
                null,
                $slug,
                ChangeTypeEnum::ASSIGNED,
                $events,
            );
        });
    }

    /** @return SlugMutationResultDTO */
    private function change(
        BindingIdentityDTO $identity,
        string $value,
        bool $generated,
        int $expectedRevision,
        AuditContextDTO $audit,
        OperationTypeEnum $operation,
    ): SlugMutationResultDTO {
        $profile = $this->profiles->get($identity->scopeProfile->expectedProfileKey);
        $candidateList = $generated ? $this->candidates->fromSource($profile, $value) : [$profile->canonicalizeClaim($value)->slug];
        $intent = ['mode' => $generated ? 'GENERATED' : 'EXACT', 'value' => $generated ? $value : $candidateList[0]->value];
        $fingerprint = $this->fingerprint($operation, $identity, $intent, null, $expectedRevision, null, null);

        return $this->transactions->run(function () use ($identity, $candidateList, $generated, $expectedRevision, $audit, $operation, $fingerprint): SlugMutationResultDTO {
            [$binding, $claims] = $this->lockExistingBinding($identity);
            $reservation = $this->reserve($operation, 'mutation', $fingerprint, [$binding->id], $audit);
            if ($reservation !== null && ! $reservation->created) {
                return $this->replayMutation($reservation);
            }
            $this->assertRevision($binding, $expectedRevision);
            $this->assertCurrentBearingBinding($binding);
            $scopeId = $this->scopeId($claims);
            $selectedSlug = null;
            $selectedClaim = null;
            $action = 'new';
            foreach ($candidateList as $candidate) {
                $same = $this->claimInRecords($claims, $candidate);
                if ($same !== null) {
                    if ($same->role === RegistryRoleEnum::CURRENT_CANONICAL) {
                        $selectedClaim = $same;
                        $selectedSlug = $candidate;
                        $action = 'noop';
                        break;
                    }
                    if ($same->role === RegistryRoleEnum::HISTORICAL_CANONICAL) {
                        $selectedClaim = $same;
                        $selectedSlug = $candidate;
                        $action = 'restore';
                        break;
                    }
                    throw new SlugAliasOperationNotPermittedException('Change cannot operate on an alias claim.');
                }
                $owner = $this->registry->findClaimByScopeSlug($scopeId, $candidate, true);
                if ($owner !== null) {
                    if (! $generated) {
                        throw new SlugAlreadyClaimedException('The requested slug is owned by another Binding.');
                    }
                    continue;
                }
                if ($this->reservations->isReserved($identity->scopeProfile->scope, $candidate)) {
                    if (! $generated) {
                        throw new SlugReservedException('The requested slug is reserved.');
                    }
                    continue;
                }
                $selectedSlug = $candidate;
                break;
            }
            if ($selectedSlug === null) {
                throw new SlugAllocationExhaustedException('All generated slug candidates are unavailable.');
            }
            $current = $this->currentClaim($claims);
            $currentDto = $current->toDto($this->profiles);
            if ($action === 'noop') {
                $after = $this->requiredBinding($identity, true);
                return $this->finishMutation($operation, $reservation, $binding, $after, $this->claimsDto($binding->id, true), $currentDto->slug, $currentDto->slug, ChangeTypeEnum::CHANGED, []);
            }
            $eventType = $action === 'restore' ? HistoryEventTypeEnum::RESTORED : HistoryEventTypeEnum::CHANGED;
            if ($action === 'restore') {
                if ($selectedClaim === null) {
                    throw new SlugPersistenceInvariantException('Historical change target disappeared before mutation.');
                }
                $this->registry->updateClaimRole($current->id, RegistryRoleEnum::HISTORICAL_CANONICAL);
                $this->registry->updateClaimRole($selectedClaim->id, RegistryRoleEnum::CURRENT_CANONICAL);
                $selectedDto = $selectedClaim->toDto($this->profiles);
            } else {
                $this->registry->updateClaimRole($current->id, RegistryRoleEnum::HISTORICAL_CANONICAL);
                $newClaim = $this->registry->insertClaim($scopeId, $binding->id, $selectedSlug, RegistryRoleEnum::CURRENT_CANONICAL);
                $selectedDto = $newClaim->toDto($this->profiles);
                $selectedClaim = $newClaim;
            }
            $events = $this->appendEvents($binding->id, $binding->state->historySequence, [$this->claimEvent($eventType, $identity, $selectedDto->slug, $currentDto->slug, RegistryRoleEnum::CURRENT_CANONICAL, RegistryRoleEnum::CURRENT_CANONICAL, null, null, $reservation, $audit)]);
            $this->registry->mutateBinding($binding->id, $binding->state->revision, $binding->state->status, $selectedClaim->id, $binding->state->historySequence + 1);
            $after = $this->requiredBinding($identity, true);
            return $this->finishMutation($operation, $reservation, $binding, $after, $this->claimsDto($binding->id, true), $currentDto->slug, $selectedDto->slug, $eventType === HistoryEventTypeEnum::RESTORED ? ChangeTypeEnum::RESTORED : ChangeTypeEnum::CHANGED, $events);
        });
    }

    private function statusMutation(
        BindingIdentityDTO $identity,
        int $expectedRevision,
        AuditContextDTO $audit,
        OperationTypeEnum $operation,
        BindingStatusEnum $from,
        BindingStatusEnum $to,
        HistoryEventTypeEnum $eventType,
        ChangeTypeEnum $changeType,
    ): SlugMutationResultDTO {
        $fingerprint = $this->fingerprint($operation, $identity, null, null, $expectedRevision, null, null);
        return $this->transactions->run(function () use ($identity, $expectedRevision, $audit, $operation, $from, $to, $eventType, $changeType, $fingerprint): SlugMutationResultDTO {
            [$binding] = $this->lockExistingBinding($identity);
            $reservation = $this->reserve($operation, 'mutation', $fingerprint, [$binding->id], $audit);
            if ($reservation !== null && ! $reservation->created) {
                return $this->replayMutation($reservation);
            }
            $this->assertRevision($binding, $expectedRevision);
            if ($binding->state->status !== $from) {
                throw new SlugAssignmentNotPermittedException(sprintf('%s requires a %s Binding.', $operation->value, $from->value));
            }
            $events = $this->appendEvents($binding->id, $binding->state->historySequence, [$this->markerEvent($eventType, $identity, $reservation, $audit)]);
            $this->registry->mutateBinding($binding->id, $binding->state->revision, $to, $binding->currentClaim?->id, $binding->state->historySequence + 1);
            $after = $this->requiredBinding($identity, true);
            return $this->finishMutation($operation, $reservation, $binding, $after, $this->claimsDto($binding->id, true), $after->state->currentSlug, $after->state->currentSlug, $changeType, $events);
        });
    }

    private function aliasMutation(
        BindingIdentityDTO $identity,
        string $candidate,
        int $expectedRevision,
        AuditContextDTO $audit,
        OperationTypeEnum $operation,
        string $mode,
    ): SlugMutationResultDTO {
        $profile = $this->profiles->get($identity->scopeProfile->expectedProfileKey);
        $slug = $profile->canonicalizeClaim($candidate)->slug;
        $fingerprint = $this->fingerprint($operation, $identity, ['mode' => 'EXACT', 'value' => $slug->value], null, $expectedRevision, null, null);
        return $this->transactions->run(function () use ($identity, $slug, $expectedRevision, $audit, $operation, $mode, $fingerprint): SlugMutationResultDTO {
            [$binding, $claims] = $this->lockExistingBinding($identity);
            $reservation = $this->reserve($operation, 'mutation', $fingerprint, [$binding->id], $audit);
            if ($reservation !== null && ! $reservation->created) {
                return $this->replayMutation($reservation);
            }
            $this->assertRevision($binding, $expectedRevision);
            $this->assertCurrentBearingBinding($binding);
            $scopeId = $this->scopeId($claims);
            $target = $this->claimInRecords($claims, $slug);
            if ($mode === 'add') {
                if ($target !== null) {
                    if ($target->role === RegistryRoleEnum::ACTIVE_ALIAS) {
                        $after = $this->requiredBinding($identity, true);
                        return $this->finishMutation($operation, $reservation, $binding, $after, $this->claimsDto($binding->id, true), $after->state->currentSlug, $after->state->currentSlug, ChangeTypeEnum::ALIAS_ADDED, []);
                    }
                    if ($target->role === RegistryRoleEnum::HISTORICAL_CANONICAL) {
                        $this->registry->updateClaimRole($target->id, RegistryRoleEnum::ACTIVE_ALIAS);
                        $event = $this->claimEvent(HistoryEventTypeEnum::ALIAS_ADDED, $identity, $slug, $slug, RegistryRoleEnum::ACTIVE_ALIAS, RegistryRoleEnum::HISTORICAL_CANONICAL, null, null, $reservation, $audit);
                    } else {
                        throw new SlugAliasOperationNotPermittedException('The requested role cannot be added as an alias.');
                    }
                } else {
                    $owner = $this->registry->findClaimByScopeSlug($scopeId, $slug, true);
                    if ($owner !== null) {
                        throw new SlugAlreadyClaimedException('The requested slug is owned by another Binding.');
                    }
                    if ($this->reservations->isReserved($identity->scopeProfile->scope, $slug)) {
                        throw new SlugReservedException('The requested slug is reserved.');
                    }
                    $target = $this->registry->insertClaim($scopeId, $binding->id, $slug, RegistryRoleEnum::ACTIVE_ALIAS);
                    $event = $this->claimEvent(HistoryEventTypeEnum::ALIAS_ADDED, $identity, $slug, null, RegistryRoleEnum::ACTIVE_ALIAS, null, null, null, $reservation, $audit);
                }
                $events = $this->appendEvents($binding->id, $binding->state->historySequence, [$event]);
                $this->registry->mutateBinding($binding->id, $binding->state->revision, $binding->state->status, $binding->currentClaim?->id, $binding->state->historySequence + 1);
                $after = $this->requiredBinding($identity, true);
                return $this->finishMutation($operation, $reservation, $binding, $after, $this->claimsDto($binding->id, true), null, $after->state->currentSlug, ChangeTypeEnum::ALIAS_ADDED, $events);
            }
            if ($target === null) {
                $owner = $this->registry->findClaimByScopeSlug($scopeId, $slug, true);
                if ($owner !== null) {
                    throw new SlugAlreadyClaimedException('The requested slug is owned by another Binding.');
                }
                throw new SlugNotFoundException('The requested alias claim was not found.');
            }
            $oldRole = $target->role;
            $newRole = match ($mode) {
                'retire' => RegistryRoleEnum::RETIRED_ALIAS,
                'reactivate' => RegistryRoleEnum::ACTIVE_ALIAS,
                'promote' => RegistryRoleEnum::CURRENT_CANONICAL,
                default => throw new SlugPersistenceInvariantException('Unknown alias mutation mode.'),
            };
            $valid = $mode === 'retire'
                ? $oldRole === RegistryRoleEnum::ACTIVE_ALIAS
                : ($mode === 'reactivate' ? $oldRole === RegistryRoleEnum::RETIRED_ALIAS : $oldRole === RegistryRoleEnum::ACTIVE_ALIAS);
            if (! $valid) {
                throw new SlugAliasOperationNotPermittedException('The requested alias role transition is not permitted.');
            }
            $oldCurrent = $mode === 'promote' ? $this->currentClaim($claims) : null;
            if ($oldCurrent !== null) {
                $this->registry->updateClaimRole($oldCurrent->id, RegistryRoleEnum::HISTORICAL_CANONICAL);
            }
            $this->registry->updateClaimRole($target->id, $newRole);
            $eventType = $mode === 'retire' ? HistoryEventTypeEnum::ALIAS_RETIRED : ($mode === 'reactivate' ? HistoryEventTypeEnum::ALIAS_REACTIVATED : HistoryEventTypeEnum::ALIAS_PROMOTED);
            $oldCurrentSlug = $oldCurrent === null ? null : $oldCurrent->toDto($this->profiles)->slug;
            $event = $this->claimEvent($eventType, $identity, $slug, $mode === 'promote' ? $oldCurrentSlug : $slug, $newRole, $oldCurrent === null ? $oldRole : RegistryRoleEnum::CURRENT_CANONICAL, null, null, $reservation, $audit);
            $events = $this->appendEvents($binding->id, $binding->state->historySequence, [$event]);
            $this->registry->mutateBinding($binding->id, $binding->state->revision, $binding->state->status, $mode === 'promote' ? $target->id : $binding->currentClaim?->id, $binding->state->historySequence + 1);
            $after = $this->requiredBinding($identity, true);
            return $this->finishMutation($operation, $reservation, $binding, $after, $this->claimsDto($binding->id, true), $oldCurrentSlug, $after->state->currentSlug, $mode === 'retire' ? ChangeTypeEnum::ALIAS_RETIRED : ($mode === 'reactivate' ? ChangeTypeEnum::ALIAS_REACTIVATED : ChangeTypeEnum::ALIAS_PROMOTED), $events);
        });
    }

    public function assignGenerated(AssignGeneratedCommand $command): SlugMutationResultDTO
    {
        $profile = $this->profiles->get($command->binding->scopeProfile->expectedProfileKey);
        $candidates = $this->candidates->fromSource($profile, $command->sourceText);
        $fingerprint = $this->fingerprint(
            OperationTypeEnum::ASSIGN_GENERATED,
            $command->binding,
            ['mode' => 'GENERATED', 'value' => $command->sourceText],
            null,
            $command->expectedRevision,
            null,
            null,
        );

        return $this->transactions->run(function () use ($command, $candidates, $fingerprint): SlugMutationResultDTO {
            $scope = $this->registry->ensureScope($command->binding->scopeProfile->scope, $command->binding->scopeProfile->expectedProfileKey);
            $lockedScope = $this->registry->lockScope($scope->scope, $scope->profileKey);
            $record = $this->registry->lockOrCreateBinding($lockedScope, $command->binding->entity);
            $before = $record->createdInCurrentTransaction ? null : $this->binding($command->binding, true);
            $reservation = $this->reserve(
                OperationTypeEnum::ASSIGN_GENERATED,
                'mutation',
                $fingerprint,
                [$record->id],
                $command->audit,
            );
            if ($reservation !== null && ! $reservation->created) {
                return $this->replayMutation($reservation);
            }
            $this->assertAssignmentState($record, $command->expectedRevision);

            $claim = null;
            foreach ($candidates as $candidate) {
                $existing = $this->registry->findClaimByScopeSlug($lockedScope->id, $candidate, true);
                if ($existing !== null) {
                    if ($existing->bindingId === $record->id) {
                        throw new SlugAssignmentNotPermittedException('Generated assignment cannot reuse a retained same-Binding role.');
                    }
                    continue;
                }
                if ($this->reservations->isReserved($lockedScope->scope, $candidate)) {
                    continue;
                }
                try {
                    $claim = $this->registry->insertClaim($lockedScope->id, $record->id, $candidate, RegistryRoleEnum::CURRENT_CANONICAL);
                } catch (PDOException $exception) {
                    if (! $this->duplicate($exception, 'uk_registry_scope_slug')) {
                        throw $exception;
                    }
                    continue;
                }
                break;
            }
            if ($claim === null) {
                throw new SlugAllocationExhaustedException('All generated slug candidates are unavailable.');
            }
            $events = $this->appendEvents(
                $record->id,
                $before?->state->historySequence ?? 0,
                [$this->claimEvent(
                    HistoryEventTypeEnum::ASSIGNED,
                    $command->binding,
                    $claim->toDto($this->profiles)->slug,
                    null,
                    RegistryRoleEnum::CURRENT_CANONICAL,
                    null,
                    null,
                    null,
                    $reservation,
                    $command->audit,
                )],
            );
            $this->registry->mutateBinding($record->id, $record->revision, BindingStatusEnum::ACTIVE, $claim->id, ($before?->state->historySequence ?? 0) + 1);
            $after = $this->requiredBinding($command->binding, true);

            return $this->finishMutation(
                OperationTypeEnum::ASSIGN_GENERATED,
                $reservation,
                $before,
                $after,
                $this->claimsDto($record->id, true),
                null,
                $claim->toDto($this->profiles)->slug,
                ChangeTypeEnum::ASSIGNED,
                $events,
            );
        });
    }

    public function changeExact(ChangeExactCommand $command): SlugMutationResultDTO
    {
        return $this->change($command->binding, $command->slugCandidate, false, $command->expectedRevision, $command->audit, OperationTypeEnum::CHANGE_EXACT);
    }

    public function changeGenerated(ChangeGeneratedCommand $command): SlugMutationResultDTO
    {
        return $this->change($command->binding, $command->sourceText, true, $command->expectedRevision, $command->audit, OperationTypeEnum::CHANGE_GENERATED);
    }

    public function restoreHistorical(RestoreHistoricalCommand $command): SlugMutationResultDTO
    {
        $profile = $this->profiles->get($command->binding->scopeProfile->expectedProfileKey);
        $slug = $profile->canonicalizeClaim($command->slugCandidate)->slug;
        $fingerprint = $this->fingerprint(OperationTypeEnum::RESTORE_HISTORICAL, $command->binding, ['mode' => 'EXACT', 'value' => $slug->value], null, $command->expectedRevision, null, null);

        return $this->transactions->run(function () use ($command, $slug, $fingerprint): SlugMutationResultDTO {
            [$binding, $claims] = $this->lockExistingBinding($command->binding);
            $reservation = $this->reserve(OperationTypeEnum::RESTORE_HISTORICAL, 'mutation', $fingerprint, [$binding->id], $command->audit);
            if ($reservation !== null && ! $reservation->created) {
                return $this->replayMutation($reservation);
            }
            $this->assertRevision($binding, $command->expectedRevision);
            $this->assertCurrentBearingBinding($binding);
            $target = $this->claimInRecords($claims, $slug);
            if ($target === null || $target->role !== RegistryRoleEnum::HISTORICAL_CANONICAL) {
                throw new SlugHistoricalRestoreNotPermittedException('Only a historical canonical claim can be restored.');
            }
            $current = $this->currentClaim($claims);
            $this->registry->updateClaimRole($current->id, RegistryRoleEnum::HISTORICAL_CANONICAL);
            $this->registry->updateClaimRole($target->id, RegistryRoleEnum::CURRENT_CANONICAL);
            $events = $this->appendEvents($binding->id, $binding->state->historySequence, [$this->claimEvent(HistoryEventTypeEnum::RESTORED, $command->binding, $slug, $current->toDto($this->profiles)->slug, RegistryRoleEnum::CURRENT_CANONICAL, RegistryRoleEnum::CURRENT_CANONICAL, null, null, $reservation, $command->audit)]);
            $this->registry->mutateBinding($binding->id, $binding->state->revision, $binding->state->status, $target->id, $binding->state->historySequence + 1);
            $after = $this->requiredBinding($command->binding, true);
            return $this->finishMutation(OperationTypeEnum::RESTORE_HISTORICAL, $reservation, $binding, $after, $this->claimsDto($binding->id, true), $current->toDto($this->profiles)->slug, $slug, ChangeTypeEnum::RESTORED, $events);
        });
    }

    public function deactivate(DeactivateBindingCommand $command): SlugMutationResultDTO
    {
        return $this->statusMutation($command->binding, $command->expectedRevision, $command->audit, OperationTypeEnum::DEACTIVATE, BindingStatusEnum::ACTIVE, BindingStatusEnum::INACTIVE, HistoryEventTypeEnum::DEACTIVATED, ChangeTypeEnum::DEACTIVATED);
    }

    public function reactivate(ReactivateBindingCommand $command): SlugMutationResultDTO
    {
        return $this->statusMutation($command->binding, $command->expectedRevision, $command->audit, OperationTypeEnum::REACTIVATE, BindingStatusEnum::INACTIVE, BindingStatusEnum::ACTIVE, HistoryEventTypeEnum::REACTIVATED, ChangeTypeEnum::REACTIVATED);
    }

    public function addAlias(AddAliasCommand $command): SlugMutationResultDTO
    {
        return $this->aliasMutation($command->binding, $command->slugCandidate, $command->expectedRevision, $command->audit, OperationTypeEnum::ADD_ALIAS, 'add');
    }

    public function retireAlias(RetireAliasCommand $command): SlugMutationResultDTO
    {
        return $this->aliasMutation($command->binding, $command->slugCandidate, $command->expectedRevision, $command->audit, OperationTypeEnum::RETIRE_ALIAS, 'retire');
    }

    public function reactivateAlias(ReactivateAliasCommand $command): SlugMutationResultDTO
    {
        return $this->aliasMutation($command->binding, $command->slugCandidate, $command->expectedRevision, $command->audit, OperationTypeEnum::REACTIVATE_ALIAS, 'reactivate');
    }

    public function promoteAliasToCurrent(PromoteAliasToCurrentCommand $command): SlugMutationResultDTO
    {
        return $this->aliasMutation($command->binding, $command->slugCandidate, $command->expectedRevision, $command->audit, OperationTypeEnum::PROMOTE_ALIAS, 'promote');
    }

    public function releaseClaim(ReleaseClaimCommand $command): SlugMutationResultDTO
    {
        $profile = $this->profiles->get($command->binding->scopeProfile->expectedProfileKey);
        $slug = $profile->canonicalizeClaim($command->slugCandidate)->slug;
        $fingerprint = $this->fingerprint(OperationTypeEnum::RELEASE_CLAIM, $command->binding, ['mode' => 'EXACT', 'value' => $slug->value], null, $command->expectedRevision, null, null);

        return $this->transactions->run(function () use ($command, $slug, $fingerprint): SlugMutationResultDTO {
            [$binding, $claims] = $this->lockExistingBinding($command->binding);
            $reservation = $this->reserve(OperationTypeEnum::RELEASE_CLAIM, 'mutation', $fingerprint, [$binding->id], $command->audit);
            if ($reservation !== null && ! $reservation->created) {
                return $this->replayMutation($reservation);
            }
            $this->assertRevision($binding, $command->expectedRevision);
            $claim = $this->claimInRecords($claims, $slug);
            if ($claim === null) {
                throw new SlugNotFoundException('The requested Binding claim was not found.');
            }
            if ($claim->role === RegistryRoleEnum::CURRENT_CANONICAL) {
                throw new SlugCurrentClaimReleaseException('The current claim cannot be released directly.');
            }
            $event = $this->claimEvent(HistoryEventTypeEnum::OWNERSHIP_RELEASED, $command->binding, $slug, null, $claim->role, null, null, null, $reservation, $command->audit);
            $events = $this->appendEvents($binding->id, $binding->state->historySequence, [$event]);
            $this->registry->deleteClaim($claim->id);
            $this->registry->mutateBinding($binding->id, $binding->state->revision, $binding->state->status, $binding->currentClaim?->id, $binding->state->historySequence + 1);
            $after = $this->requiredBinding($command->binding, true);
            return $this->finishMutation(OperationTypeEnum::RELEASE_CLAIM, $reservation, $binding, $after, $this->claimsDto($binding->id, true), $slug, $after->state->currentSlug, ChangeTypeEnum::RELEASED, $events);
        });
    }

    public function releaseAllOwnership(ReleaseAllOwnershipCommand $command): SlugMutationResultDTO
    {
        $fingerprint = $this->fingerprint(OperationTypeEnum::RELEASE_ALL, $command->binding, null, null, $command->expectedRevision, null, null);

        return $this->transactions->run(function () use ($command, $fingerprint): SlugMutationResultDTO {
            [$binding, $claims] = $this->lockExistingBinding($command->binding);
            $reservation = $this->reserve(OperationTypeEnum::RELEASE_ALL, 'mutation', $fingerprint, [$binding->id], $command->audit);
            if ($reservation !== null && ! $reservation->created) {
                return $this->replayMutation($reservation);
            }
            $this->assertRevision($binding, $command->expectedRevision);
            if (! in_array($binding->state->status, [BindingStatusEnum::ACTIVE, BindingStatusEnum::INACTIVE], true)) {
                throw new SlugAssignmentNotPermittedException('Release-all requires an ACTIVE or INACTIVE Binding.');
            }
            $drafts = [$this->markerEvent(HistoryEventTypeEnum::OWNERSHIP_RELEASED_ALL, $command->binding, $reservation, $command->audit)];
            foreach ($claims as $claim) {
                $drafts[] = $this->claimEvent(HistoryEventTypeEnum::OWNERSHIP_RELEASED, $command->binding, $claim->toDto($this->profiles)->slug, null, $claim->role, null, null, null, $reservation, $command->audit);
            }
            $events = $this->appendEvents($binding->id, $binding->state->historySequence, $drafts);
            foreach ($claims as $claim) {
                $this->registry->deleteClaim($claim->id);
            }
            $this->registry->mutateBinding($binding->id, $binding->state->revision, BindingStatusEnum::RELEASED, null, $binding->state->historySequence + count($drafts));
            $after = $this->requiredBinding($command->binding, true);
            return $this->finishMutation(OperationTypeEnum::RELEASE_ALL, $reservation, $binding, $after, [], $binding->state->currentSlug, null, ChangeTypeEnum::RELEASED_ALL, $events);
        });
    }

    public function purgeBinding(PurgeBindingCommand $command): void
    {
        $this->capabilities->assertInstalledSchemaSupported();
        $this->transactions->run(function () use ($command): void {
            $binding = $this->binding($command->binding, true);
            if ($binding === null || $binding->state->status !== BindingStatusEnum::RELEASED || $binding->currentClaim !== null) {
                throw new SlugPurgeNotPermittedException('Only a RELEASED Binding with zero live claims can be purged.');
            }
            if ($binding->state->revision !== $command->expectedRevision) {
                throw new SlugRevisionConflictException('Binding revision does not match purge request.');
            }
            $this->maintenance->purge($binding->id);
        });
    }

    public function atomicTransfer(AtomicTransferCommand $command): AtomicTransferResultDTO
    {
        $sourceProfile = $this->profiles->get($command->source->scopeProfile->expectedProfileKey);
        $this->profiles->get($command->target->scopeProfile->expectedProfileKey);
        if ($command->source->scopeProfile->expectedProfileKey->value !== $command->target->scopeProfile->expectedProfileKey->value) {
            throw new SlugScopeProfileMismatchException('Atomic transfer source and target must use the same Scope profile.');
        }
        $transferredSlug = $sourceProfile->canonicalizeClaim($command->slugCandidate)->slug;
        $replacementCandidates = $this->transferReplacementCandidates($command, $sourceProfile);
        $replacementPayload = $command->sourceReplacementIntent === null ? null : [
            'mode' => $command->sourceReplacementIntent->mode->value,
            'value' => $command->sourceReplacementIntent->mode->value === 'EXACT'
                ? $replacementCandidates[0]->value
                : $command->sourceReplacementIntent->value,
        ];
        $fingerprint = $this->fingerprint(
            OperationTypeEnum::ATOMIC_TRANSFER,
            $command->source,
            ['mode' => 'EXACT', 'value' => $transferredSlug->value],
            $replacementPayload,
            null,
            $command->sourceExpectedRevision,
            $command->targetExpectedRevision,
            $command->target,
        );
        $this->capabilities->assertInstalledSchemaSupported();

        return $this->transactions->run(function () use ($command, $transferredSlug, $replacementCandidates, $fingerprint): AtomicTransferResultDTO {
            [$source, $sourceClaims, $target, $targetClaims, $lockedClaims] = $this->lockTransferParticipants(
                $command->source,
                $command->target,
                $transferredSlug,
                $replacementCandidates,
            );
            $reservation = $this->reserve(
                OperationTypeEnum::ATOMIC_TRANSFER,
                'transfer',
                $fingerprint,
                [$source->id, $target->id],
                $command->audit,
                ['SOURCE', 'TARGET'],
            );
            if ($reservation !== null && ! $reservation->created) {
                return $this->replayTransfer($reservation);
            }
            $this->assertRevision($source, $command->sourceExpectedRevision);
            $this->assertRevision($target, $command->targetExpectedRevision);
            if (! in_array($source->state->status, [BindingStatusEnum::ACTIVE, BindingStatusEnum::INACTIVE], true)) {
                throw new SlugAssignmentNotPermittedException('Transfer source must be ACTIVE or INACTIVE.');
            }
            $moved = $this->claimInRecords($sourceClaims, $transferredSlug);
            if ($moved === null) {
                throw new SlugNotFoundException('The source transfer claim was not found.');
            }
            $originalRole = $moved->role;
            $targetConflict = $this->claimInRecords($lockedClaims, $transferredSlug);
            if ($targetConflict !== null && $targetConflict->id !== $moved->id) {
                throw new SlugAlreadyClaimedException('The transferred slug is already claimed in the target Scope.');
            }
            if ($originalRole === RegistryRoleEnum::CURRENT_CANONICAL) {
                if ($target->state->status !== BindingStatusEnum::RELEASED) {
                    throw new SlugAssignmentNotPermittedException('Current transfer requires a RELEASED target Binding.');
                }
                if ($command->sourceReplacementIntent === null) {
                    throw new SlugTransferReplacementConflictException('Current transfer requires a source replacement intent.');
                }
            } else {
                if ($command->sourceReplacementIntent !== null) {
                    throw new SlugInvalidArgumentException('Non-current transfer cannot carry a source replacement intent.');
                }
                if (! in_array($target->state->status, [BindingStatusEnum::ACTIVE, BindingStatusEnum::INACTIVE], true)) {
                    throw new SlugAssignmentNotPermittedException('Non-current transfer requires an ACTIVE or INACTIVE target Binding.');
                }
            }
            if ($this->reservations->isReserved($command->target->scopeProfile->scope, $transferredSlug)) {
                throw new SlugReservedException('The transferred slug is reserved for the target Scope.');
            }

            $replacement = null;
            $replacementEvent = null;
            if ($originalRole === RegistryRoleEnum::CURRENT_CANONICAL) {
                [$replacement, $replacementEvent] = $this->prepareTransferReplacement(
                    $command,
                    $source,
                    $sourceClaims,
                    $lockedClaims,
                    $replacementCandidates,
                    $transferredSlug,
                    $reservation,
                );
                $current = $this->currentClaim($sourceClaims);
                $this->registry->updateClaimRole($current->id, RegistryRoleEnum::HISTORICAL_CANONICAL);
                if ($replacement['kind'] === 'RESTORED') {
                    if ($replacement['claim'] === null) {
                        throw new SlugPersistenceInvariantException('Historical replacement claim disappeared before transfer.');
                    }
                    $replacement['claim'] = $this->registry->updateClaimRole($replacement['claim']->id, RegistryRoleEnum::CURRENT_CANONICAL);
                } else {
                    $replacement['claim'] = $this->registry->insertClaim($moved->scopeId, $source->id, $replacement['slug'], RegistryRoleEnum::CURRENT_CANONICAL);
                }
            }

            $this->registry->deleteClaim($moved->id);
            $transferred = $this->registry->insertClaim($moved->scopeId, $target->id, $transferredSlug, $originalRole);
            $sourceDrafts = [];
            if ($replacementEvent !== null) {
                $sourceDrafts[] = $replacementEvent;
            }
            $sourceDrafts[] = $this->claimEvent(HistoryEventTypeEnum::OWNERSHIP_TRANSFERRED_OUT, $command->source, $transferredSlug, null, $originalRole, null, $command->target->scopeProfile->scope, $command->target->entity, $reservation, $command->audit);
            $targetDraft = $this->claimEvent(HistoryEventTypeEnum::OWNERSHIP_TRANSFERRED_IN, $command->target, $transferredSlug, null, $originalRole, null, $command->source->scopeProfile->scope, $command->source->entity, $reservation, $command->audit);
            $sourceEvents = $this->appendEvents($source->id, $source->state->historySequence, $sourceDrafts);
            $targetEvents = $this->appendEvents($target->id, $target->state->historySequence, [$targetDraft]);
            if ($replacement === null) {
                $sourceCurrentId = $source->currentClaim?->id;
            } else {
                $replacementClaim = $replacement['claim'];
                $sourceCurrentId = $replacementClaim->id;
            }
            if ($sourceCurrentId === null) {
                throw new SlugPersistenceInvariantException('Source current pointer was not retained during transfer.');
            }
            $this->registry->mutateBinding($source->id, $source->state->revision, $source->state->status, $sourceCurrentId, $source->state->historySequence + count($sourceDrafts));
            $targetStatus = $originalRole === RegistryRoleEnum::CURRENT_CANONICAL ? BindingStatusEnum::ACTIVE : $target->state->status;
            $this->registry->mutateBinding($target->id, $target->state->revision, $targetStatus, $originalRole === RegistryRoleEnum::CURRENT_CANONICAL ? $transferred->id : $target->currentClaim?->id, $target->state->historySequence + 1);
            $sourceAfter = $this->requiredBinding($command->source, true);
            $targetAfter = $this->requiredBinding($command->target, true);
            $sourceHistory = $sourceEvents;
            $targetHistory = $targetEvents;
            $sourceParticipant = new BindingStateResultDTO($source, $sourceAfter, true, $sourceAfter->state->revision, $sourceHistory);
            $targetParticipant = new BindingStateResultDTO($target, $targetAfter, true, $targetAfter->state->revision, $targetHistory);
            $nested = null;
            if ($replacement !== null) {
                $replacementDto = $replacement['claim']->toDto($this->profiles);
                $replacementAfterClaims = $this->claimsDto($source->id, true);
                $nested = new SlugMutationResultDTO(
                    OperationTypeEnum::ATOMIC_TRANSFER,
                    $this->operationKey($reservation),
                    false,
                    $source,
                    $sourceAfter,
                    $replacementAfterClaims,
                    $transferredSlug,
                    $replacementDto->slug,
                    $replacement['kind'] === 'RESTORED' ? ChangeTypeEnum::RESTORED : ChangeTypeEnum::CHANGED,
                    $sourceAfter->state->revision,
                    [$sourceEvents[0]],
                );
            }
            $result = new AtomicTransferResultDTO(
                OperationTypeEnum::ATOMIC_TRANSFER,
                $this->operationKey($reservation),
                false,
                $sourceParticipant,
                $targetParticipant,
                $transferred->toDto($this->profiles),
                $nested,
                $sourceAfter->state->revision,
                $targetAfter->state->revision,
                array_merge($sourceHistory, $targetHistory),
            );
            $this->commitTransferSnapshot($reservation, $result);
            return $result;
        });
    }

    /** @return array{0: BindingDTO, 1: list<RegistryClaimRecord>} */
    private function lockExistingBinding(BindingIdentityDTO $identity): array
    {
        $binding = $this->binding($identity, true);
        if ($binding === null) {
            throw new SlugNotFoundException('The requested Binding was not found.');
        }
        $claims = $this->registry->findClaimsForBinding($binding->id, true);

        return [$binding, $claims];
    }

    /**
     * @param list<Slug> $replacementCandidates
     * @return array{0: BindingDTO, 1: list<RegistryClaimRecord>, 2: BindingDTO, 3: list<RegistryClaimRecord>, 4: list<RegistryClaimRecord>}
     */
    private function lockTransferParticipants(
        BindingIdentityDTO $sourceIdentity,
        BindingIdentityDTO $targetIdentity,
        Slug $transferredSlug,
        array $replacementCandidates,
    ): array {
        $scopeIdentities = [$sourceIdentity, $targetIdentity];
        usort($scopeIdentities, fn(BindingIdentityDTO $left, BindingIdentityDTO $right): int => strcmp($this->scopeLockKey($left), $this->scopeLockKey($right)));
        $lockedScopes = [];
        foreach ($scopeIdentities as $identity) {
            $scopeKey = $this->scopeLockKey($identity);
            if (isset($lockedScopes[$scopeKey])) {
                continue;
            }
            $lockedScopes[$scopeKey] = $this->registry->lockScope($identity->scopeProfile->scope, $identity->scopeProfile->expectedProfileKey);
        }

        $bindingRequests = [
            [$lockedScopes[$this->scopeLockKey($sourceIdentity)], $sourceIdentity],
            [$lockedScopes[$this->scopeLockKey($targetIdentity)], $targetIdentity],
        ];
        usort($bindingRequests, static function (array $left, array $right): int {
            $scopeOrder = $left[0]->id <=> $right[0]->id;
            if ($scopeOrder !== 0) {
                return $scopeOrder;
            }
            $entityTypeOrder = strcmp($left[1]->entity->entityType, $right[1]->entity->entityType);
            if ($entityTypeOrder !== 0) {
                return $entityTypeOrder;
            }

            return strcmp($left[1]->entity->entityKey, $right[1]->entity->entityKey);
        });

        $lockedBindings = [];
        foreach ($bindingRequests as [$scope, $identity]) {
            $record = $this->registry->findBindingRecord($scope, $identity->entity, true);
            if ($record === null) {
                throw new SlugNotFoundException('The requested transfer Binding was not found.');
            }
            $lockedBindings[$this->bindingLockKey($identity)] = $record;
        }
        $sourceRecord = $lockedBindings[$this->bindingLockKey($sourceIdentity)];
        $targetRecord = $lockedBindings[$this->bindingLockKey($targetIdentity)];
        if ($sourceRecord->id === $targetRecord->id) {
            throw new SlugInvalidArgumentException('Atomic transfer source and target must differ.');
        }

        $participantClaims = $this->registry->findClaimsForBindings([$sourceRecord->id, $targetRecord->id]);
        $registryKeys = [$transferredSlug->value];
        foreach ($replacementCandidates as $candidate) {
            $registryKeys[] = $candidate->value;
        }
        foreach ($participantClaims as $claim) {
            $registryKeys[] = $claim->slugValue;
        }
        usort($registryKeys, static fn(string $left, string $right): int => strcmp($left, $right));
        $registryKeys = array_values(array_unique($registryKeys, SORT_STRING));
        $minimumSlug = $registryKeys[0];
        $maximumSlug = $registryKeys[count($registryKeys) - 1];
        $claims = $this->registry->findClaimsInScopeSlugRange(
            $sourceRecord->scopeId,
            $minimumSlug,
            $maximumSlug,
            true,
        );
        $source = $this->requiredBinding($sourceIdentity, true);
        $target = $this->requiredBinding($targetIdentity, true);

        return [
            $source,
            $this->claimsForBinding($claims, $sourceRecord->id),
            $target,
            $this->claimsForBinding($claims, $targetRecord->id),
            $claims,
        ];
    }

    /** @param list<RegistryClaimRecord> $claims
     *  @return list<RegistryClaimRecord>
     */
    private function claimsForBinding(array $claims, int $bindingId): array
    {
        return array_values(array_filter($claims, static fn(RegistryClaimRecord $claim): bool => $claim->bindingId === $bindingId));
    }

    private function binding(BindingIdentityDTO $identity, bool $forUpdate): ?BindingDTO
    {
        return $this->registry->binding($identity, $forUpdate);
    }

    private function bindingLockKey(BindingIdentityDTO $identity): string
    {
        return $this->scopeLockKey($identity) . "\0"
            . $identity->entity->entityType . "\0"
            . $identity->entity->entityKey;
    }

    private function scopeLockKey(BindingIdentityDTO $identity): string
    {
        return $identity->scopeProfile->scope->namespace . "\0"
            . ($identity->scopeProfile->scope->localeKey ?? '') . "\0"
            . ($identity->scopeProfile->scope->contextKey ?? '');
    }

    private function requiredBinding(BindingIdentityDTO $identity, bool $forUpdate): BindingDTO
    {
        $binding = $this->binding($identity, $forUpdate);
        if ($binding === null) {
            throw new SlugPersistenceInvariantException('Binding disappeared during lifecycle mutation.');
        }

        return $binding;
    }

    private function assertRevision(BindingDTO $binding, int $expectedRevision): void
    {
        if ($binding->state->revision !== $expectedRevision) {
            throw new SlugRevisionConflictException('The Binding revision does not match the lifecycle request.');
        }
    }

    private function assertAssignmentState(RegistryBindingRecord $binding, ?int $expectedRevision): void
    {
        if ($binding->createdInCurrentTransaction) {
            if ($expectedRevision !== null) {
                throw new SlugRevisionConflictException('An absent Binding requires a NULL expected revision.');
            }
            return;
        }
        if ($expectedRevision === null || $binding->revision !== $expectedRevision) {
            throw new SlugRevisionConflictException('A RELEASED Binding requires its current revision.');
        }
        if ($binding->status !== BindingStatusEnum::RELEASED) {
            throw new SlugAssignmentNotPermittedException('Assignment requires an absent or RELEASED Binding.');
        }
    }

    private function assertCurrentBearingBinding(BindingDTO $binding): void
    {
        if (! in_array($binding->state->status, [BindingStatusEnum::ACTIVE, BindingStatusEnum::INACTIVE], true) || $binding->currentClaim === null) {
            throw new SlugAssignmentNotPermittedException('This lifecycle operation requires an ACTIVE or INACTIVE Binding.');
        }
    }

    /** @param list<RegistryClaimRecord> $claims */
    private function currentClaim(array $claims): RegistryClaimRecord
    {
        $current = array_values(array_filter($claims, static fn(RegistryClaimRecord $claim): bool => $claim->role === RegistryRoleEnum::CURRENT_CANONICAL));
        if (count($current) !== 1) {
            throw new SlugPersistenceInvariantException('Binding must have exactly one current canonical claim.');
        }

        return $current[0];
    }

    /** @param list<RegistryClaimRecord> $claims */
    private function claimInRecords(array $claims, Slug $slug): ?RegistryClaimRecord
    {
        foreach ($claims as $claim) {
            if ($claim->slugValue === $slug->value) {
                return $claim;
            }
        }

        return null;
    }

    /** @param list<RegistryClaimRecord> $claims */
    private function scopeId(array $claims): int
    {
        if ($claims === []) {
            throw new SlugPersistenceInvariantException('A current-bearing Binding must expose a Scope id.');
        }

        return $claims[0]->scopeId;
    }

    /** @return list<RegistryClaimDTO> */
    private function claimsDto(int $bindingId, bool $forUpdate): array
    {
        $claims = $this->registry->findClaimsForBinding($bindingId, $forUpdate);
        $dtos = array_map(fn(RegistryClaimRecord $claim): RegistryClaimDTO => $claim->toDto($this->profiles), $claims);
        usort($dtos, static fn(RegistryClaimDTO $left, RegistryClaimDTO $right): int => $left->id <=> $right->id);

        return $dtos;
    }

    /**
     * @param list<HistoryEventDraft> $drafts
     * @return list<HistoryEventDTO>
     */
    private function appendEvents(int $bindingId, int $startingSequence, array $drafts): array
    {
        $events = [];
        foreach ($drafts as $index => $draft) {
            if ($draft->bindingId !== 0 && $draft->bindingId !== $bindingId) {
                throw new SlugPersistenceInvariantException('History draft Binding does not match the participant.');
            }
            $sequenced = new HistoryEventDraft(
                $bindingId,
                $startingSequence + $index + 1,
                $draft->eventType,
                $draft->scopeSnapshot,
                $draft->entitySnapshot,
                $draft->slugSnapshot,
                $draft->previousSlugSnapshot,
                $draft->claimRoleSnapshot,
                $draft->previousClaimRoleSnapshot,
                $draft->relatedScope,
                $draft->relatedEntity,
                $draft->operationId,
                $draft->operationKey,
                $draft->audit,
                $draft->occurredAt,
                $draft->originalOccurredAt,
            );
            $events[] = $this->history->append($sequenced);
        }

        return $events;
    }

    private function claimEvent(
        HistoryEventTypeEnum $eventType,
        BindingIdentityDTO $identity,
        Slug $slug,
        ?Slug $previousSlug,
        RegistryRoleEnum $role,
        ?RegistryRoleEnum $previousRole,
        ?SlugScope $relatedScope,
        ?\Maatify\Slug\Identity\EntityReference $relatedEntity,
        ?OperationReservation $reservation,
        AuditContextDTO $audit,
    ): HistoryEventDraft {
        return new HistoryEventDraft(
            0,
            0,
            $eventType,
            $identity->scopeProfile->scope,
            $identity->entity,
            $slug,
            $previousSlug,
            $role,
            $previousRole,
            $relatedScope,
            $relatedEntity,
            $reservation?->operation->id,
            $this->operationKey($reservation),
            $audit,
            $this->clock->now(),
        );
    }

    private function markerEvent(
        HistoryEventTypeEnum $eventType,
        BindingIdentityDTO $identity,
        ?OperationReservation $reservation,
        AuditContextDTO $audit,
    ): HistoryEventDraft {
        return new HistoryEventDraft(
            0,
            0,
            $eventType,
            $identity->scopeProfile->scope,
            $identity->entity,
            null,
            null,
            null,
            null,
            null,
            null,
            $reservation?->operation->id,
            $this->operationKey($reservation),
            $audit,
            $this->clock->now(),
        );
    }

    private function operationKey(?OperationReservation $reservation): ?string
    {
        return $reservation?->operation->operationKey;
    }

    /**
     * @param list<int> $bindingIds
     * @param list<string>|null $roles
     */
    private function reserve(
        OperationTypeEnum $operation,
        string $resultType,
        string $fingerprint,
        array $bindingIds,
        AuditContextDTO $audit,
        ?array $roles = null,
    ): ?OperationReservation {
        if ($audit->idempotencyKey === null) {
            return null;
        }
        $resolvedRoles = $roles ?? (count($bindingIds) === 1 ? ['SINGLE'] : ['SOURCE', 'TARGET']);
        $existingKey = null;
        foreach ($bindingIds as $bindingId) {
            $existing = $this->operations->findByParticipant($bindingId, $audit->idempotencyKey, true);
            if ($existing !== null) {
                $existingKey = $existing->operationKey;
                break;
            }
        }
        $operationKey = $existingKey ?? $this->newOperationKey();
        $participants = [];
        foreach ($bindingIds as $index => $bindingId) {
            $participants[] = new OperationParticipant($bindingId, $audit->idempotencyKey, $resolvedRoles[$index]);
        }

        return $this->operations->reserve($operationKey, $operation, $fingerprint, $resultType, 1, $participants);
    }

    private function newOperationKey(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable $throwable) {
            throw new SlugPersistenceInvariantException('Unable to allocate an operation key.', 0, $throwable);
        }
    }

    private function duplicate(PDOException $exception, string $constraint): bool
    {
        return PdoDuplicateClassifier::matches($exception, $constraint);
    }

    private function replayMutation(OperationReservation $reservation): SlugMutationResultDTO
    {
        $result = $this->operations->decodeCommitted($reservation->operation->id, $this->profiles);
        if (! $result instanceof SlugMutationResultDTO) {
            throw new SlugPersistenceInvariantException('Mutation operation contains a non-mutation snapshot.');
        }

        return $result;
    }

    private function replayTransfer(OperationReservation $reservation): AtomicTransferResultDTO
    {
        $result = $this->operations->decodeCommitted($reservation->operation->id, $this->profiles);
        if (! $result instanceof AtomicTransferResultDTO) {
            throw new SlugPersistenceInvariantException('Transfer operation contains a non-transfer snapshot.');
        }

        return $result;
    }

    /**
     * @param list<RegistryClaimDTO> $affected
     * @param list<HistoryEventDTO> $events
     */
    private function finishMutation(
        OperationTypeEnum $operation,
        ?OperationReservation $reservation,
        ?BindingDTO $before,
        BindingDTO $after,
        array $affected,
        ?Slug $previousSlug,
        ?Slug $currentSlug,
        ChangeTypeEnum $changeType,
        array $events,
    ): SlugMutationResultDTO {
        $result = new SlugMutationResultDTO(
            $operation,
            $this->operationKey($reservation),
            false,
            $before,
            $after,
            $affected,
            $previousSlug,
            $currentSlug,
            $changeType,
            $after->state->revision,
            $events,
        );
        if ($reservation !== null) {
            $snapshot = ResultSnapshotEncoder::encode($result);
            $this->operations->commitSnapshot($reservation->operation->id, new ResultSnapshotMetadata('mutation', 1, $operation, $reservation->operation->operationKey, $snapshot), $this->profiles);
        }

        return $result;
    }

    private function commitTransferSnapshot(?OperationReservation $reservation, AtomicTransferResultDTO $result): void
    {
        if ($reservation === null) {
            return;
        }
        $snapshot = ResultSnapshotEncoder::encode($result);
        $this->operations->commitSnapshot($reservation->operation->id, new ResultSnapshotMetadata('transfer', 1, OperationTypeEnum::ATOMIC_TRANSFER, $reservation->operation->operationKey, $snapshot), $this->profiles);
    }

    /**
     * @param array{mode: string, value: string}|null $claimIntent
     * @param array{mode: string, value: string}|null $replacementIntent
     */
    private function fingerprint(
        OperationTypeEnum $operation,
        BindingIdentityDTO $source,
        ?array $claimIntent,
        ?array $replacementIntent,
        ?int $expectedRevision,
        ?int $sourceExpectedRevision,
        ?int $targetExpectedRevision,
        ?BindingIdentityDTO $target = null,
    ): string {
        $payload = [
            'contract_version' => 1,
            'operation_type' => $operation->value,
            'source_scope' => [
                'namespace' => $source->scopeProfile->scope->namespace,
                'locale_key' => $source->scopeProfile->scope->localeKey,
                'context_key' => $source->scopeProfile->scope->contextKey,
                'profile_key' => $source->scopeProfile->expectedProfileKey->value,
            ],
            'source_binding' => [
                'entity_type' => $source->entity->entityType,
                'entity_key' => $source->entity->entityKey,
            ],
            'target_scope' => null,
            'target_binding' => null,
            'claim_intent' => $claimIntent,
            'source_replacement_intent' => $replacementIntent,
            'transition_mode' => null,
            'expected_revision' => $expectedRevision,
            'source_expected_revision' => $sourceExpectedRevision,
            'target_expected_revision' => $targetExpectedRevision,
            'original_occurred_at' => null,
        ];
        if ($target !== null) {
            $payload['target_scope'] = [
                'namespace' => $target->scopeProfile->scope->namespace,
                'locale_key' => $target->scopeProfile->scope->localeKey,
                'context_key' => $target->scopeProfile->scope->contextKey,
                'profile_key' => $target->scopeProfile->expectedProfileKey->value,
            ];
            $payload['target_binding'] = [
                'entity_type' => $target->entity->entityType,
                'entity_key' => $target->entity->entityKey,
            ];
        }
        try {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new SlugPersistenceInvariantException('Request fingerprint encoding failed.', 0, $exception);
        }

        return hash('sha256', $json);
    }

    /** @return list<Slug> */
    private function transferReplacementCandidates(AtomicTransferCommand $command, SlugProfileInterface $profile): array
    {
        $intent = $command->sourceReplacementIntent;
        if ($intent === null) {
            return [];
        }

        if ($intent->mode->value === 'EXACT') {
            return [$profile->canonicalizeClaim($intent->value)->slug];
        }

        return $this->candidates->fromSource($profile, $intent->value);
    }

    /**
     * @param list<RegistryClaimRecord> $sourceClaims
     * @param list<RegistryClaimRecord> $lockedClaims
     * @param list<Slug> $candidates
     * @return array{0: array{kind: string, slug: Slug, claim: ?RegistryClaimRecord}, 1: HistoryEventDraft}
     */
    private function prepareTransferReplacement(
        AtomicTransferCommand $command,
        BindingDTO $source,
        array $sourceClaims,
        array $lockedClaims,
        array $candidates,
        Slug $moved,
        ?OperationReservation $reservation,
    ): array {
        $intent = $command->sourceReplacementIntent;
        if ($intent === null) {
            throw new SlugTransferReplacementConflictException('Current transfer requires a replacement intent.');
        }
        $selection = null;
        foreach ($candidates as $candidate) {
            if ($candidate->value === $moved->value) {
                if ($intent->mode->value === 'EXACT') {
                    throw new SlugTransferReplacementConflictException('Replacement cannot equal the transferred slug.');
                }
                continue;
            }
            $same = $this->claimInRecords($sourceClaims, $candidate);
            if ($same !== null) {
                if ($same->role === RegistryRoleEnum::HISTORICAL_CANONICAL) {
                    $selection = ['kind' => 'RESTORED', 'slug' => $candidate, 'claim' => $same];
                    break;
                }
                if (in_array($same->role, [RegistryRoleEnum::ACTIVE_ALIAS, RegistryRoleEnum::RETIRED_ALIAS], true)) {
                    throw new SlugAliasOperationNotPermittedException('Transfer replacement cannot use an alias claim.');
                }
                continue;
            }
            $owner = $this->claimInRecords($lockedClaims, $candidate);
            if ($owner !== null) {
                if ($intent->mode->value === 'EXACT') {
                    throw new SlugAlreadyClaimedException('The exact replacement is owned by another Binding.');
                }
                continue;
            }
            if ($this->reservations->isReserved($command->source->scopeProfile->scope, $candidate)) {
                if ($intent->mode->value === 'EXACT') {
                    throw new SlugReservedException('The exact replacement is reserved.');
                }
                continue;
            }
            $selection = ['kind' => 'CHANGED', 'slug' => $candidate, 'claim' => null];
            break;
        }
        if ($selection === null) {
            throw new SlugAllocationExhaustedException('All transfer replacement candidates are unavailable.');
        }
        $event = $this->claimEvent(
            $selection['kind'] === 'RESTORED' ? HistoryEventTypeEnum::RESTORED : HistoryEventTypeEnum::CHANGED,
            $command->source,
            $selection['slug'],
            $moved,
            RegistryRoleEnum::CURRENT_CANONICAL,
            RegistryRoleEnum::CURRENT_CANONICAL,
            null,
            null,
            $reservation,
            $command->audit,
        );

        return [$selection, $event];
    }
}
