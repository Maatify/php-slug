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
 * revision used for optimistic CAS; assignment accepts null only when the
 * binding has no prior state. Replayed idempotency keys return the stored
 * result, while canonicalization, reservation, ownership, state, and revision
 * precondition failures are surfaced as domain exceptions.
 */
interface SlugLifecycleServiceInterface
{
    /**
     * Assigns an exact canonical claim, creating the binding when the expected
     * revision is null; rejects non-canonical, reserved, occupied, or retained
     * same-binding claims and records the assignment in history.
     */
    public function assignExact(AssignExactCommand $command): SlugMutationResultDTO;

    /**
     * Generates candidates from source text and assigns the first available
     * claim atomically, preserving the same null-revision creation rule and
     * reporting allocation exhaustion or reservation/ownership conflicts.
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
     * Executes a transactional MOVE or PARALLEL transition with an exact or
     * generated target claim intent. MOVE transfers source ownership and
     * PARALLEL retains it; source revision, target occupancy, profile, and
     * transition-state preconditions are enforced and replay is supported.
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
     * Adds or restores an ACTIVE_ALIAS claim for an active binding without
     * changing its current pointer; canonicalization, reservation, occupancy,
     * revision, and replay rules are applied transactionally.
     */
    public function addAlias(AddAliasCommand $command): SlugMutationResultDTO;

    /**
     * Changes an ACTIVE_ALIAS to RETIRED_ALIAS at the expected revision while
     * preserving the claim's history and binding ownership record.
     */
    public function retireAlias(RetireAliasCommand $command): SlugMutationResultDTO;

    /**
     * Changes a RETIRED_ALIAS back to ACTIVE_ALIAS after CAS, retaining history
     * and rejecting claims that are absent or in another role.
     */
    public function reactivateAlias(ReactivateAliasCommand $command): SlugMutationResultDTO;

    /**
     * Promotes an ACTIVE_ALIAS to current, demoting the former current claim to
     * historical ownership in the same transaction and preserving replay data.
     */
    public function promoteAliasToCurrent(PromoteAliasToCurrentCommand $command): SlugMutationResultDTO;

    /**
     * Adopts an existing current external claim into package-owned lifecycle
     * state; a null expected revision is valid only for a new binding, while an
     * optional original occurrence time is normalized into adoption history.
     */
    public function adoptCurrent(AdoptCurrentCommand $command): AdoptionResultDTO;

    /**
     * Adopts an existing historical canonical claim at the expected revision,
     * preserving its historical role and optional original occurrence time;
     * ownership and revision preconditions are atomic and replayable.
     */
    public function adoptHistorical(AdoptHistoricalCommand $command): AdoptionResultDTO;

    /**
     * Adopts an existing alias claim without promoting it to current, enforcing
     * the expected revision, role, ownership, and optional historical timestamp
     * preconditions in one replayable transaction.
     */
    public function adoptAlias(AdoptAliasCommand $command): AdoptionResultDTO;

    /**
     * Permanently deletes a RELEASED binding with zero live claims after the
     * expected-revision check; this destructive operation is transactional and
     * rejects active ownership, current claims, missing bindings, or stale CAS.
     */
    public function purgeBinding(PurgeBindingCommand $command): void;
}
