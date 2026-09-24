<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Service;

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
use Maatify\Slug\Lifecycle\DTO\AdoptionResultDTO;
use Maatify\Slug\Lifecycle\DTO\AtomicTransferResultDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeTransitionResultDTO;
use Maatify\Slug\Lifecycle\DTO\SlugMutationResultDTO;

/**
 * Atomic public mutation contract for the persisted slug lifecycle.
 *
 * Implementations mutate the binding, current/alias/history ownership, and
 * operation replay records in one transaction. Commands carry the expected
 * revision used for optimistic CAS; assignment accepts either a null
 * revision for an absent binding or a matching revision for a RELEASED
 * binding. Replayed idempotency keys return the stored result, while
 * canonicalization, reservation, ownership, state, and revision precondition
 * failures are surfaced as domain exceptions.
 */
interface SlugLifecycleServiceInterface
{
    /**
     * Assigns an exact canonical claim. The binding must be absent with a null
     * expected revision, or RELEASED with a matching current revision; the
     * successful assignment establishes current ownership and history.
     * Canonicalization, reservation, occupancy, same-binding retention, and
     * idempotent replay rules are enforced transactionally.
     */
    public function assignExact(AssignExactCommand $command): SlugMutationResultDTO;

    /**
     * Generates ordered candidates from source text and assigns the first
     * available claim atomically. The binding must be absent with a null
     * expected revision, or RELEASED with a matching current revision;
     * allocation exhaustion, reservation, occupancy, ownership, and replay
     * rules are enforced.
     */
    public function assignGenerated(AssignGeneratedCommand $command): SlugMutationResultDTO;

    /**
     * Changes the current claim to an exact canonical value at the expected
     * revision; the previous current remains historical and the mutation is
     * idempotently replayable through the command audit context.
     */
    public function changeExact(ChangeExactCommand $command): SlugMutationResultDTO;

    /**
     * Tries the ordered generated candidates at the expected revision until a
     * claim can become current, retaining prior ownership as history and
     * failing when every candidate is reserved or occupied.
     */
    public function changeGenerated(ChangeGeneratedCommand $command): SlugMutationResultDTO;

    /**
     * Restores an owned historical canonical claim as current using optimistic
     * CAS; the former current becomes historical and the event is transactional
     * and replayable.
     */
    public function restoreHistorical(RestoreHistoricalCommand $command): SlugMutationResultDTO;

    /**
     * Changes ACTIVE to INACTIVE without deleting current, alias, or history
     * records; the expected revision and state precondition are enforced in the
     * same transaction.
     */
    public function deactivate(DeactivateBindingCommand $command): SlugMutationResultDTO;

    /**
     * Changes INACTIVE back to ACTIVE without replacing the current claim;
     * revision conflicts and invalid source states are domain failures.
     */
    public function reactivate(ReactivateBindingCommand $command): SlugMutationResultDTO;

    /**
     * Deletes one non-current owned claim after CAS while retaining its history;
     * a current claim cannot be released directly and an absent claim is a
     * not-found failure.
     */
    public function releaseClaim(ReleaseClaimCommand $command): SlugMutationResultDTO;

    /**
     * Deletes all owned claims and moves an ACTIVE or INACTIVE binding to
     * RELEASED while retaining lifecycle history; the whole release is atomic
     * and idempotently replayable.
     */
    public function releaseAllOwnership(ReleaseAllOwnershipCommand $command): SlugMutationResultDTO;

    /**
     * Creates an independent target Binding and current claim in a distinct
     * target Scope under one transaction. EXACT uses one canonical target
     * candidate and GENERATED uses ordered candidates; the target Binding must
     * be absent on first execution, with an idempotent replay able to return
     * the stored result.
     *
     * MOVE marks the source Binding INACTIVE but retains its current claim and
     * source Scope identity; PARALLEL leaves the source Binding unchanged.
     * Source CAS, profile, occupancy, reservation, and transition-state rules
     * are enforced.
     */
    public function transitionScope(TransitionScopeCommand $command): ScopeTransitionResultDTO;

    /**
     * Atomically transfers one claim between distinct bindings in the same
     * scope. Both source and target revisions are CAS guards; a current source
     * requires a RELEASED target plus an exact/generated replacement intent,
     * while a non-current transfer forbids replacement and requires an active
     * or inactive target.
     */
    public function atomicTransfer(AtomicTransferCommand $command): AtomicTransferResultDTO;

    /**
     * Adds or restores an ACTIVE_ALIAS claim on a current-bearing Binding whose
     * state is ACTIVE or INACTIVE, using its matching current revision without
     * changing the current pointer. Canonicalization, reservation, occupancy,
     * ownership, and replay rules are applied transactionally.
     */
    public function addAlias(AddAliasCommand $command): SlugMutationResultDTO;

    /**
     * Changes an ACTIVE_ALIAS to RETIRED_ALIAS on a current-bearing ACTIVE or
     * INACTIVE Binding at its matching revision, preserving current ownership,
     * claim history, and replay semantics.
     */
    public function retireAlias(RetireAliasCommand $command): SlugMutationResultDTO;

    /**
     * Changes a RETIRED_ALIAS back to ACTIVE_ALIAS after matching CAS on a
     * current-bearing ACTIVE or INACTIVE Binding, retaining history and
     * rejecting absent or role-incompatible claims transactionally.
     */
    public function reactivateAlias(ReactivateAliasCommand $command): SlugMutationResultDTO;

    /**
     * Promotes an ACTIVE_ALIAS to current on a current-bearing ACTIVE or
     * INACTIVE Binding at its matching revision, demoting the former current
     * claim to historical ownership; the transition is transactional and
     * replayable.
     */
    public function promoteAliasToCurrent(PromoteAliasToCurrentCommand $command): SlugMutationResultDTO;

    /**
     * Adopts an external claim as current. The binding must be absent with a
     * null expected revision, or RELEASED with a matching current revision;
     * either path establishes current ownership. The optional original
     * occurrence time is normalized into adoption history and replay is
     * transactional.
     */
    public function adoptCurrent(AdoptCurrentCommand $command): AdoptionResultDTO;

    /**
     * Adopts an external claim as historical without promoting it to current.
     * It requires an existing ACTIVE or INACTIVE current-bearing Binding and
     * its matching current revision; the current pointer remains unchanged.
     * The optional original occurrence time is normalized, with ownership,
     * transaction, and replay semantics enforced.
     */
    public function adoptHistorical(AdoptHistoricalCommand $command): AdoptionResultDTO;

    /**
     * Adopts an external claim as an alias without promoting it to current. It
     * requires an existing ACTIVE or INACTIVE current-bearing Binding and its
     * matching current revision; the optional original occurrence time is
     * normalized and role, ownership, transaction, and replay rules apply.
     */
    public function adoptAlias(AdoptAliasCommand $command): AdoptionResultDTO;

    /**
     * Permanently deletes a RELEASED binding with zero live claims after the
     * expected-revision check; this destructive operation is transactional and
     * rejects active ownership, current claims, missing bindings, or stale CAS.
     */
    public function purgeBinding(PurgeBindingCommand $command): void;
}
