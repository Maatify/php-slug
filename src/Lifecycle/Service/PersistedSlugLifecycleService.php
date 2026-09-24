<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Service;

use Maatify\Slug\Lifecycle\Service\Adoption\AdoptionService;
use Maatify\Slug\Lifecycle\Command\AddAliasCommand;
use Maatify\Slug\Lifecycle\Command\AdoptAliasCommand;
use Maatify\Slug\Lifecycle\Command\AdoptCurrentCommand;
use Maatify\Slug\Lifecycle\Command\AdoptHistoricalCommand;
use Maatify\Slug\Lifecycle\Command\AssignExactCommand;
use Maatify\Slug\Lifecycle\Command\AssignGeneratedCommand;
use Maatify\Slug\Lifecycle\Command\AtomicTransferCommand;
use Maatify\Slug\Lifecycle\Command\ChangeExactCommand;
use Maatify\Slug\Lifecycle\Command\ChangeGeneratedCommand;
use Maatify\Slug\Lifecycle\Command\DeactivateBindingCommand;
use Maatify\Slug\Lifecycle\Command\PurgeBindingCommand;
use Maatify\Slug\Lifecycle\Command\ReactivateAliasCommand;
use Maatify\Slug\Lifecycle\Command\ReactivateBindingCommand;
use Maatify\Slug\Lifecycle\Command\ReleaseAllOwnershipCommand;
use Maatify\Slug\Lifecycle\Command\ReleaseClaimCommand;
use Maatify\Slug\Lifecycle\Command\PromoteAliasToCurrentCommand;
use Maatify\Slug\Lifecycle\Command\RestoreHistoricalCommand;
use Maatify\Slug\Lifecycle\Command\RetireAliasCommand;
use Maatify\Slug\Lifecycle\Command\TransitionScopeCommand;
use Maatify\Slug\Lifecycle\Service\SlugLifecycleServiceInterface;
use Maatify\Slug\Lifecycle\DTO\AdoptionResultDTO;
use Maatify\Slug\Lifecycle\DTO\AtomicTransferResultDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeTransitionResultDTO;
use Maatify\Slug\Lifecycle\DTO\SlugMutationResultDTO;
use Maatify\Slug\Lifecycle\Service\SlugLifecycleService;
use Maatify\Slug\Lifecycle\Service\Transition\ScopeTransitionService;

/**
 * Composite adapter exposing the complete persisted lifecycle boundary.
 *
 * It routes core mutations, scope transitions, and adoption operations to
 * their specialized services without changing the interface contract's CAS,
 * transaction, ownership, precondition, or idempotent replay semantics.
 */
final readonly class PersistedSlugLifecycleService implements SlugLifecycleServiceInterface
{
    /** Combines core lifecycle, scope-transition, and adoption implementations. */
    public function __construct(
        private SlugLifecycleService $legacyLifecycle,
        private ScopeTransitionService $transition,
        private AdoptionService $adoption,
    ) {}

    /** Delegates exact assignment without changing canonicalization, CAS, atomicity, or replay semantics. */
    public function assignExact(AssignExactCommand $command): SlugMutationResultDTO
    {
        return $this->legacyLifecycle->assignExact($command);
    }
    /** Delegates generated assignment without changing candidate, reservation, CAS, or replay semantics. */
    public function assignGenerated(AssignGeneratedCommand $command): SlugMutationResultDTO
    {
        return $this->legacyLifecycle->assignGenerated($command);
    }
    /** Delegates exact change without changing history ownership, CAS, atomicity, or replay semantics. */
    public function changeExact(ChangeExactCommand $command): SlugMutationResultDTO
    {
        return $this->legacyLifecycle->changeExact($command);
    }
    /** Delegates generated change without changing candidate ordering, CAS, or atomicity semantics. */
    public function changeGenerated(ChangeGeneratedCommand $command): SlugMutationResultDTO
    {
        return $this->legacyLifecycle->changeGenerated($command);
    }
    /** Delegates historical restoration without changing role, CAS, atomicity, or replay semantics. */
    public function restoreHistorical(RestoreHistoricalCommand $command): SlugMutationResultDTO
    {
        return $this->legacyLifecycle->restoreHistorical($command);
    }
    /** Delegates deactivation without changing retained ownership, state, or CAS semantics. */
    public function deactivate(DeactivateBindingCommand $command): SlugMutationResultDTO
    {
        return $this->legacyLifecycle->deactivate($command);
    }
    /** Delegates reactivation without changing retained ownership, state, or CAS semantics. */
    public function reactivate(ReactivateBindingCommand $command): SlugMutationResultDTO
    {
        return $this->legacyLifecycle->reactivate($command);
    }
    /** Delegates non-current claim release without changing history retention or CAS semantics. */
    public function releaseClaim(ReleaseClaimCommand $command): SlugMutationResultDTO
    {
        return $this->legacyLifecycle->releaseClaim($command);
    }
    /** Delegates release-all without changing ownership cleanup, history, atomicity, or replay semantics. */
    public function releaseAllOwnership(ReleaseAllOwnershipCommand $command): SlugMutationResultDTO
    {
        return $this->legacyLifecycle->releaseAllOwnership($command);
    }
    /** Delegates MOVE/PARALLEL transition without changing claim intent, CAS, atomicity, or replay semantics. */
    public function transitionScope(TransitionScopeCommand $command): ScopeTransitionResultDTO
    {
        return $this->transition->transitionScope($command);
    }
    /** Delegates atomic transfer without changing source/target CAS or replacement constraints. */
    public function atomicTransfer(AtomicTransferCommand $command): AtomicTransferResultDTO
    {
        return $this->legacyLifecycle->atomicTransfer($command);
    }
    /** Delegates alias creation without changing role, reservation, CAS, or replay semantics. */
    public function addAlias(AddAliasCommand $command): SlugMutationResultDTO
    {
        return $this->legacyLifecycle->addAlias($command);
    }
    /** Delegates alias retirement without changing role preconditions, history, or CAS semantics. */
    public function retireAlias(RetireAliasCommand $command): SlugMutationResultDTO
    {
        return $this->legacyLifecycle->retireAlias($command);
    }
    /** Delegates alias reactivation without changing role preconditions, history, or CAS semantics. */
    public function reactivateAlias(ReactivateAliasCommand $command): SlugMutationResultDTO
    {
        return $this->legacyLifecycle->reactivateAlias($command);
    }
    /** Delegates alias promotion without changing current/history ownership, CAS, or replay semantics. */
    public function promoteAliasToCurrent(PromoteAliasToCurrentCommand $command): SlugMutationResultDTO
    {
        return $this->legacyLifecycle->promoteAliasToCurrent($command);
    }
    /** Delegates current adoption without changing absent-revision, timestamp, CAS, or replay semantics. */
    public function adoptCurrent(AdoptCurrentCommand $command): AdoptionResultDTO
    {
        return $this->adoption->adoptCurrent($command);
    }
    /** Delegates historical adoption without changing role, timestamp, CAS, or replay semantics. */
    public function adoptHistorical(AdoptHistoricalCommand $command): AdoptionResultDTO
    {
        return $this->adoption->adoptHistorical($command);
    }
    /** Delegates alias adoption without changing role, timestamp, CAS, or replay semantics. */
    public function adoptAlias(AdoptAliasCommand $command): AdoptionResultDTO
    {
        return $this->adoption->adoptAlias($command);
    }
    /** Delegates destructive purge without changing RELEASED/zero-claims or CAS preconditions. */
    public function purgeBinding(PurgeBindingCommand $command): void
    {
        $this->legacyLifecycle->purgeBinding($command);
    }
}
