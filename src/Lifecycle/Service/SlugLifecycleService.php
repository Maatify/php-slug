<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Service;

use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Slug\Lifecycle\Service\Allocation\GeneratedCandidateSequence;
use Maatify\Slug\Lifecycle\Command\AddAliasCommand;
use Maatify\Slug\Lifecycle\Command\AssignExactCommand;
use Maatify\Slug\Lifecycle\Command\AssignGeneratedCommand;
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
use Maatify\Slug\Lifecycle\Command\RestoreHistoricalCommand;
use Maatify\Slug\Lifecycle\Command\RetireAliasCommand;
use Maatify\Slug\Lifecycle\Contract\ReservedSlugPolicyInterface;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\BindingStateResultDTO;
use Maatify\Slug\Lifecycle\DTO\AtomicTransferResultDTO;
use Maatify\Slug\Lifecycle\DTO\HistoryEventDTO;
use Maatify\Slug\Lifecycle\DTO\RegistryClaimDTO;
use Maatify\Slug\Lifecycle\DTO\SlugMutationResultDTO;
use Maatify\Slug\Lifecycle\Enum\BindingStatusEnum;
use Maatify\Slug\Lifecycle\Enum\ChangeTypeEnum;
use Maatify\Slug\Lifecycle\Enum\HistoryEventTypeEnum;
use Maatify\Slug\Lifecycle\Enum\OperationTypeEnum;
use Maatify\Slug\Lifecycle\Enum\RegistryRoleEnum;
use Maatify\Slug\Lifecycle\Exception\SlugAliasOperationNotPermittedException;
use Maatify\Slug\Lifecycle\Exception\SlugAlreadyClaimedException;
use Maatify\Slug\Lifecycle\Exception\SlugAllocationExhaustedException;
use Maatify\Slug\Lifecycle\Exception\SlugAssignmentNotPermittedException;
use Maatify\Slug\Lifecycle\Exception\SlugCurrentClaimReleaseException;
use Maatify\Slug\Lifecycle\Exception\SlugHistoricalRestoreNotPermittedException;
use Maatify\Slug\Lifecycle\Exception\SlugIdempotencyConflictException;
use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\Exception\SlugNotFoundException;
use Maatify\Slug\Lifecycle\Exception\SlugPersistenceInvariantException;
use Maatify\Slug\Lifecycle\Exception\SlugPurgeNotPermittedException;
use Maatify\Slug\Lifecycle\Exception\SlugReservedException;
use Maatify\Slug\Lifecycle\Exception\SlugRevisionConflictException;
use Maatify\Slug\Lifecycle\Exception\SlugScopeProfileMismatchException;
use Maatify\Slug\Lifecycle\Exception\SlugTransferReplacementConflictException;
use Maatify\Slug\Lifecycle\Repository\History\HistoryEventDraft;
use Maatify\Slug\Canonicalization\ValueObject\Slug;
use Maatify\Slug\Lifecycle\Repository\Registry\RegistryBindingRecord;
use Maatify\Slug\Lifecycle\Repository\Registry\RegistryClaimRecord;
use Maatify\Slug\Lifecycle\Repository\Binding\BindingMaintenanceRepositoryInterface;
use Maatify\Slug\Lifecycle\Repository\CapabilityGuardInterface;
use Maatify\Slug\Lifecycle\Repository\History\HistoryRepositoryInterface;
use Maatify\Slug\Lifecycle\Repository\Operation\ResultSnapshotMetadata;
use Maatify\Slug\Lifecycle\Repository\Operation\OperationPersistenceInterface;
use Maatify\Slug\Lifecycle\Repository\Registry\RegistryRepositoryInterface;
use Maatify\Slug\Lifecycle\Repository\Transaction\TransactionCoordinatorInterface;
use Maatify\Slug\Lifecycle\Mapper\ResultSnapshot\ResultSnapshotEncoder;
use Maatify\Slug\Lifecycle\Repository\Operation\OperationParticipant;
use Maatify\Slug\Lifecycle\Repository\Operation\OperationReservation;
use Maatify\Slug\Canonicalization\Contract\SlugProfileInterface;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistryInterface;
use Maatify\Slug\Lifecycle\Service\Reserved\ReservationEvaluator;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;
use Maatify\Slug\Lifecycle\Factory\OperationKeyFactory;
use Maatify\Slug\Lifecycle\Mapper\OperationFingerprintMapper;

/**
 * Core persisted service for binding, alias, ownership, and transfer mutations.
 *
 * Each operation coordinates repository locks, optimistic revisions, history,
 * operation reservations, and transaction boundaries. Scope transitions and
 * claim adoption are composed by the aggregate lifecycle adapter because they
 * use specialized mutation services.
 */
final class SlugLifecycleService
{
    private ReservationEvaluator $reservations;

    /** Wires the core lifecycle state machine and its transactional persistence boundaries. */
    public function __construct(
        private readonly SlugProfileRegistryInterface $profiles,
        private readonly RegistryRepositoryInterface $registry,
        private readonly HistoryRepositoryInterface $history,
        private readonly OperationPersistenceInterface $operations,
        private readonly TransactionCoordinatorInterface $transactions,
        private readonly CapabilityGuardInterface $capabilities,
        private readonly BindingMaintenanceRepositoryInterface $maintenance,
        ReservedSlugPolicyInterface $reservedPolicy,
        private readonly ClockInterface $clock,
        private readonly GeneratedCandidateSequence $candidates,
        private readonly OperationFingerprintMapper $fingerprints,
        private readonly OperationKeyFactory $operationKeys,
    ) {
        $this->reservations = new ReservationEvaluator($reservedPolicy);
    }

    /**
     * Assigns an exact canonical claim. The binding must be absent with a null
     * expected revision, or RELEASED with a matching current revision; the
     * mutation establishes current ownership and records history. Canonical,
     * reservation, occupancy, same-binding retention, and idempotent replay
     * rules are enforced inside one transaction.
     */
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
            $existing = $this->registry->findClaimByScopeSlug($lockedScope->id, $slug);
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
            if ($claim === null) {
                throw new SlugAlreadyClaimedException('The requested slug was claimed by another Binding during assignment.');
            }
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
            [$binding, $claims, $lockedClaims] = $this->lockCandidateBearingBinding($identity, $candidateList);
            $reservation = $this->reserve($operation, 'mutation', $fingerprint, [$binding->id], $audit);
            if ($reservation !== null && ! $reservation->created) {
                return $this->replayMutation($reservation);
            }
            $this->assertRevision($binding, $expectedRevision);
            $this->assertCurrentBearingBinding($binding);
            $scopeId = $this->scopeId($claims);
            $current = $this->currentClaim($claims);
            $currentDto = $current->toDto($this->profiles);
            $selectedSlug = null;
            $selectedClaim = null;
            $action = 'new';
            $currentDemoted = false;
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
                        if (! $currentDemoted) {
                            $this->registry->updateClaimRole($current->id, RegistryRoleEnum::HISTORICAL_CANONICAL);
                            $currentDemoted = true;
                        }
                        $selectedClaim = $same;
                        $selectedSlug = $candidate;
                        $action = 'restore';
                        break;
                    }
                    throw new SlugAliasOperationNotPermittedException('Change cannot operate on an alias claim.');
                }
                $owner = $this->claimInRecords($lockedClaims, $candidate);
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
                if (! $currentDemoted) {
                    $this->registry->updateClaimRole($current->id, RegistryRoleEnum::HISTORICAL_CANONICAL);
                    $currentDemoted = true;
                }
                $selectedClaim = $this->registry->insertClaim($scopeId, $binding->id, $candidate, RegistryRoleEnum::CURRENT_CANONICAL);
                if ($selectedClaim === null) {
                    if (! $generated) {
                        throw new SlugAlreadyClaimedException('The requested slug was claimed by another Binding during change.');
                    }
                    continue;
                }
                $selectedSlug = $candidate;
                break;
            }
            if ($selectedSlug === null) {
                throw new SlugAllocationExhaustedException('All generated slug candidates are unavailable.');
            }
            if ($action === 'noop') {
                $after = $this->requiredBinding($identity, true);
                return $this->finishMutation($operation, $reservation, $binding, $after, $this->claimsDto($binding->id, true), $currentDto->slug, $currentDto->slug, ChangeTypeEnum::CHANGED, []);
            }
            $eventType = $action === 'restore' ? HistoryEventTypeEnum::RESTORED : HistoryEventTypeEnum::CHANGED;
            if ($action === 'restore') {
                if ($selectedClaim === null) {
                    throw new SlugPersistenceInvariantException('Historical change target disappeared before mutation.');
                }
                $this->registry->updateClaimRole($selectedClaim->id, RegistryRoleEnum::CURRENT_CANONICAL);
                $selectedDto = $selectedClaim->toDto($this->profiles);
            } else {
                if ($selectedClaim === null) {
                    throw new SlugPersistenceInvariantException('New change claim was not inserted as current.');
                }
                $selectedDto = $selectedClaim->toDto($this->profiles);
            }
            $events = $this->appendEvents($binding->id, $binding->state->historySequence, [$this->claimEvent($eventType, $identity, $selectedDto->slug, $currentDto->slug, RegistryRoleEnum::CURRENT_CANONICAL, RegistryRoleEnum::CURRENT_CANONICAL, null, null, $reservation, $audit)]);
            $this->registry->mutateBinding($binding->id, $binding->state->revision, $binding->state->status, $selectedClaim->id, $binding->state->historySequence + 1);
            $after = $this->requiredBinding($identity, true);
            return $this->finishMutation($operation, $reservation, $binding, $after, $this->claimsDto($binding->id, true), $currentDto->slug, $selectedDto->slug, $eventType === HistoryEventTypeEnum::RESTORED ? ChangeTypeEnum::RESTORED : ChangeTypeEnum::CHANGED, $events);
        });
    }

    /** Executes a status-only mutation with idempotent replay handling. */
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

    /** Executes add, retire, reactivate, or promote alias state transitions. */
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
            [$binding, $claims, $lockedClaims] = $this->lockCandidateBearingBinding($identity, [$slug]);
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
                    $owner = $this->claimInRecords($lockedClaims, $slug);
                    if ($owner !== null) {
                        throw new SlugAlreadyClaimedException('The requested slug is owned by another Binding.');
                    }
                    if ($this->reservations->isReserved($identity->scopeProfile->scope, $slug)) {
                        throw new SlugReservedException('The requested slug is reserved.');
                    }
                    $target = $this->registry->insertClaim($scopeId, $binding->id, $slug, RegistryRoleEnum::ACTIVE_ALIAS);
                    if ($target === null) {
                        throw new SlugAlreadyClaimedException('The requested alias was claimed by another Binding.');
                    }
                    $event = $this->claimEvent(HistoryEventTypeEnum::ALIAS_ADDED, $identity, $slug, null, RegistryRoleEnum::ACTIVE_ALIAS, null, null, null, $reservation, $audit);
                }
                $events = $this->appendEvents($binding->id, $binding->state->historySequence, [$event]);
                $this->registry->mutateBinding($binding->id, $binding->state->revision, $binding->state->status, $binding->currentClaim?->id, $binding->state->historySequence + 1);
                $after = $this->requiredBinding($identity, true);
                return $this->finishMutation($operation, $reservation, $binding, $after, $this->claimsDto($binding->id, true), null, $after->state->currentSlug, ChangeTypeEnum::ALIAS_ADDED, $events);
            }
            if ($target === null) {
                $owner = $this->claimInRecords($lockedClaims, $slug);
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

    /**
     * Assigns the first available generated candidate. The binding must be
     * absent with a null expected revision, or RELEASED with a matching current
     * revision; reservation, uniqueness, history, allocation, and replay
     * semantics are enforced atomically.
     */
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
                $existing = $this->registry->findClaimByScopeSlug($lockedScope->id, $candidate);
                if ($existing !== null) {
                    if ($existing->bindingId === $record->id) {
                        throw new SlugAssignmentNotPermittedException('Generated assignment cannot reuse a retained same-Binding role.');
                    }
                    continue;
                }
                if ($this->reservations->isReserved($lockedScope->scope, $candidate)) {
                    continue;
                }
                $claim = $this->registry->insertClaim($lockedScope->id, $record->id, $candidate, RegistryRoleEnum::CURRENT_CANONICAL);
                if ($claim === null) {
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

    /**
     * Changes the current claim to an exact canonical value after CAS; the old
     * current becomes historical and the operation can replay its stored result.
     */
    public function changeExact(ChangeExactCommand $command): SlugMutationResultDTO
    {
        return $this->change($command->binding, $command->slugCandidate, false, $command->expectedRevision, $command->audit, OperationTypeEnum::CHANGE_EXACT);
    }

    /**
     * Changes the current claim using the ordered generated sequence, skipping
     * unavailable candidates and failing when allocation is exhausted.
     */
    public function changeGenerated(ChangeGeneratedCommand $command): SlugMutationResultDTO
    {
        return $this->change($command->binding, $command->sourceText, true, $command->expectedRevision, $command->audit, OperationTypeEnum::CHANGE_GENERATED);
    }

    /** Restores an owned historical canonical claim as current, demoting the former current atomically. */
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

    /** Changes ACTIVE to INACTIVE while retaining claims and history under CAS and replay rules. */
    public function deactivate(DeactivateBindingCommand $command): SlugMutationResultDTO
    {
        return $this->statusMutation($command->binding, $command->expectedRevision, $command->audit, OperationTypeEnum::DEACTIVATE, BindingStatusEnum::ACTIVE, BindingStatusEnum::INACTIVE, HistoryEventTypeEnum::DEACTIVATED, ChangeTypeEnum::DEACTIVATED);
    }

    /** Changes INACTIVE to ACTIVE without replacing the current claim under CAS and replay rules. */
    public function reactivate(ReactivateBindingCommand $command): SlugMutationResultDTO
    {
        return $this->statusMutation($command->binding, $command->expectedRevision, $command->audit, OperationTypeEnum::REACTIVATE, BindingStatusEnum::INACTIVE, BindingStatusEnum::ACTIVE, HistoryEventTypeEnum::REACTIVATED, ChangeTypeEnum::REACTIVATED);
    }

    /**
     * Adds or restores an ACTIVE_ALIAS on a current-bearing ACTIVE or INACTIVE
     * Binding at its matching revision without changing the current pointer;
     * canonicalization, reservation, occupancy, ownership, and replay rules
     * are transactional.
     */
    public function addAlias(AddAliasCommand $command): SlugMutationResultDTO
    {
        return $this->aliasMutation($command->binding, $command->slugCandidate, $command->expectedRevision, $command->audit, OperationTypeEnum::ADD_ALIAS, 'add');
    }

    /**
     * Retires an ACTIVE_ALIAS on a current-bearing ACTIVE or INACTIVE Binding
     * at its matching revision while retaining current ownership, history, and
     * replay semantics.
     */
    public function retireAlias(RetireAliasCommand $command): SlugMutationResultDTO
    {
        return $this->aliasMutation($command->binding, $command->slugCandidate, $command->expectedRevision, $command->audit, OperationTypeEnum::RETIRE_ALIAS, 'retire');
    }

    /**
     * Reactivates a RETIRED_ALIAS on a current-bearing ACTIVE or INACTIVE
     * Binding after matching revision and role checks, retaining history and
     * replay semantics.
     */
    public function reactivateAlias(ReactivateAliasCommand $command): SlugMutationResultDTO
    {
        return $this->aliasMutation($command->binding, $command->slugCandidate, $command->expectedRevision, $command->audit, OperationTypeEnum::REACTIVATE_ALIAS, 'reactivate');
    }

    /**
     * Promotes an ACTIVE_ALIAS to current on a current-bearing ACTIVE or
     * INACTIVE Binding at its matching revision and demotes the former current
     * claim to history; the mutation is transactional and replayable.
     */
    public function promoteAliasToCurrent(PromoteAliasToCurrentCommand $command): SlugMutationResultDTO
    {
        return $this->aliasMutation($command->binding, $command->slugCandidate, $command->expectedRevision, $command->audit, OperationTypeEnum::PROMOTE_ALIAS, 'promote');
    }

    /** Deletes one non-current claim after CAS while retaining its lifecycle event. */
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

    /** Moves an ACTIVE or INACTIVE binding to RELEASED and deletes all live claims atomically. */
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

    /** Permanently deletes a RELEASED, zero-live-claim binding after capability and CAS checks. */
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

    /**
     * Transfers one claim atomically between distinct bindings in one scope;
     * current transfers require a released target and exact/generated source
     * replacement, while non-current transfers forbid replacement.
     */
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
                $current = $this->currentClaim($sourceClaims);
                $this->registry->updateClaimRole($current->id, RegistryRoleEnum::HISTORICAL_CANONICAL);
                [$replacement, $replacementEvent] = $this->prepareTransferReplacement(
                    $command,
                    $source,
                    $sourceClaims,
                    $lockedClaims,
                    $replacementCandidates,
                    $transferredSlug,
                    $reservation,
                );
                if ($replacement['kind'] === 'RESTORED') {
                    if ($replacement['claim'] === null) {
                        throw new SlugPersistenceInvariantException('Historical replacement claim disappeared before transfer.');
                    }
                    $replacement['claim'] = $this->registry->updateClaimRole($replacement['claim']->id, RegistryRoleEnum::CURRENT_CANONICAL);
                } else {
                    if ($replacement['claim'] === null) {
                        throw new SlugPersistenceInvariantException('New replacement claim was not inserted as current.');
                    }
                }
            }

            $this->registry->deleteClaim($moved->id);
            $transferred = $this->registry->insertClaim($moved->scopeId, $target->id, $transferredSlug, $originalRole);
            if ($transferred === null) {
                throw new SlugAlreadyClaimedException('The transferred slug was claimed concurrently.');
            }
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
     * @param list<Slug> $candidateKeys
     * @return array{0: BindingDTO, 1: list<RegistryClaimRecord>, 2: list<RegistryClaimRecord>}
     */
    private function lockCandidateBearingBinding(BindingIdentityDTO $identity, array $candidateKeys): array
    {
        $scope = $this->registry->lockScope($identity->scopeProfile->scope, $identity->scopeProfile->expectedProfileKey);
        $record = $this->registry->findBindingRecord($scope, $identity->entity, true);
        if ($record === null) {
            throw new SlugNotFoundException('The requested Binding was not found.');
        }

        $participantClaims = $this->registry->findClaimsForBinding($record->id);
        $registryKeys = array_map(static fn(Slug $slug): string => $slug->value, $candidateKeys);
        foreach ($participantClaims as $claim) {
            $registryKeys[] = $claim->slugValue;
        }
        usort($registryKeys, static fn(string $left, string $right): int => strcmp($left, $right));
        $registryKeys = array_values(array_unique($registryKeys, SORT_STRING));
        $minimumSlug = $registryKeys[0] ?? throw new SlugPersistenceInvariantException('Lifecycle Registry lock plan is empty.');
        $maximumSlug = $registryKeys[count($registryKeys) - 1];
        $lockedClaims = $this->registry->findClaimsInScopeSlugRange(
            $record->scopeId,
            $minimumSlug,
            $maximumSlug,
            true,
        );
        $binding = $this->requiredBinding($identity, false);

        return [$binding, $this->claimsForBinding($lockedClaims, $record->id), $lockedClaims];
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
        $source = $this->requiredBinding($sourceIdentity, false);
        $target = $this->requiredBinding($targetIdentity, false);

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

    /** Loads a binding by identity with optional row locking. */
    private function binding(BindingIdentityDTO $identity, bool $forUpdate): ?BindingDTO
    {
        return $this->registry->binding($identity, $forUpdate);
    }

    /** Builds the lock key that serializes one binding identity. */
    private function bindingLockKey(BindingIdentityDTO $identity): string
    {
        return $this->scopeLockKey($identity) . "\0"
            . $identity->entity->entityType . "\0"
            . $identity->entity->entityKey;
    }

    /** Builds the lock key that serializes one scope identity. */
    private function scopeLockKey(BindingIdentityDTO $identity): string
    {
        return $identity->scopeProfile->scope->namespace . "\0"
            . ($identity->scopeProfile->scope->localeKey ?? '') . "\0"
            . ($identity->scopeProfile->scope->contextKey ?? '');
    }

    /** Loads the binding required to complete a lifecycle mutation. */
    private function requiredBinding(BindingIdentityDTO $identity, bool $forUpdate): BindingDTO
    {
        $binding = $this->binding($identity, $forUpdate);
        if ($binding === null) {
            throw new SlugPersistenceInvariantException('Binding disappeared during lifecycle mutation.');
        }

        return $binding;
    }

    /** Enforces optimistic-concurrency revision matching. */
    private function assertRevision(BindingDTO $binding, int $expectedRevision): void
    {
        if ($binding->state->revision !== $expectedRevision) {
            throw new SlugRevisionConflictException('The Binding revision does not match the lifecycle request.');
        }
    }

    /** Enforces the absent-or-released state required for first assignment. */
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

    /** Enforces the active or inactive state required by claim-bearing mutations. */
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

    /** Creates a history draft for a claim-bearing lifecycle change. */
    private function claimEvent(
        HistoryEventTypeEnum $eventType,
        BindingIdentityDTO $identity,
        Slug $slug,
        ?Slug $previousSlug,
        RegistryRoleEnum $role,
        ?RegistryRoleEnum $previousRole,
        ?SlugScope $relatedScope,
        ?\Maatify\Slug\Lifecycle\ValueObject\EntityReference $relatedEntity,
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

    /** Creates a history draft for a lifecycle marker without a claim snapshot. */
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

    /** Returns the operation key associated with an optional reservation. */
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
        $operationKey = $existingKey ?? $this->operationKeys->create('Unable to allocate an operation key.');
        $participants = [];
        foreach ($bindingIds as $index => $bindingId) {
            $participants[] = new OperationParticipant($bindingId, $audit->idempotencyKey, $resolvedRoles[$index]);
        }

        return $this->operations->reserve($operationKey, $operation, $fingerprint, $resultType, 1, $participants);
    }

    /** Decodes and type-checks a committed mutation snapshot for replay. */
    private function replayMutation(OperationReservation $reservation): SlugMutationResultDTO
    {
        $result = $this->operations->decodeCommitted($reservation->operation->id, $this->profiles);
        if (! $result instanceof SlugMutationResultDTO) {
            throw new SlugPersistenceInvariantException('Mutation operation contains a non-mutation snapshot.');
        }

        return $result;
    }

    /** Decodes and type-checks a committed transfer snapshot for replay. */
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

    /** Commits an atomic-transfer result snapshot when idempotency is enabled. */
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
        return $this->fingerprints->fingerprint($payload);
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
     * The source current claim has already been demoted by the caller. A new
     * replacement is inserted directly as CURRENT_CANONICAL here so a
     * uniqueness race never creates a synthetic historical row.
     *
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
            $newClaim = $this->registry->insertClaim($this->scopeId($sourceClaims), $source->id, $candidate, RegistryRoleEnum::CURRENT_CANONICAL);
            if ($newClaim === null) {
                if ($intent->mode->value === 'EXACT') {
                    throw new SlugAlreadyClaimedException('The exact replacement was claimed by another Binding during transfer.');
                }
                continue;
            }
            $selection = ['kind' => 'CHANGED', 'slug' => $candidate, 'claim' => $newClaim];
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
