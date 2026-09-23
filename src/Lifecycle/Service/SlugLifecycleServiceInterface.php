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

/** Public mutation boundary for binding, alias, adoption, transfer, and scope lifecycle operations. */
interface SlugLifecycleServiceInterface
{
    /** Assigns a canonical exact claim to a binding. */
    public function assignExact(AssignExactCommand $command): SlugMutationResultDTO;

    /** Assigns the first available generated candidate to a binding. */
    public function assignGenerated(AssignGeneratedCommand $command): SlugMutationResultDTO;

    /** Changes a binding to a requested exact claim. */
    public function changeExact(ChangeExactCommand $command): SlugMutationResultDTO;

    /** Changes a binding using generated candidates. */
    public function changeGenerated(ChangeGeneratedCommand $command): SlugMutationResultDTO;

    /** Restores a historical claim as current. */
    public function restoreHistorical(RestoreHistoricalCommand $command): SlugMutationResultDTO;

    /** Marks a binding inactive while retaining its lifecycle records. */
    public function deactivate(DeactivateBindingCommand $command): SlugMutationResultDTO;

    /** Reactivates an inactive binding. */
    public function reactivate(ReactivateBindingCommand $command): SlugMutationResultDTO;

    /** Releases a selected claim according to the expected revision. */
    public function releaseClaim(ReleaseClaimCommand $command): SlugMutationResultDTO;

    /** Releases all ownership for a binding while retaining history. */
    public function releaseAllOwnership(ReleaseAllOwnershipCommand $command): SlugMutationResultDTO;

    /** Executes a configured MOVE or PARALLEL scope transition. */
    public function transitionScope(TransitionScopeCommand $command): ScopeTransitionResultDTO;

    /** Transfers ownership between two bindings atomically. */
    public function atomicTransfer(AtomicTransferCommand $command): AtomicTransferResultDTO;

    /** Adds a non-current alias claim. */
    public function addAlias(AddAliasCommand $command): SlugMutationResultDTO;

    /** Retires an alias claim. */
    public function retireAlias(RetireAliasCommand $command): SlugMutationResultDTO;

    /** Reactivates an eligible alias claim. */
    public function reactivateAlias(ReactivateAliasCommand $command): SlugMutationResultDTO;

    /** Promotes an alias claim to current. */
    public function promoteAliasToCurrent(PromoteAliasToCurrentCommand $command): SlugMutationResultDTO;

    /** Adopts an existing current claim. */
    public function adoptCurrent(AdoptCurrentCommand $command): AdoptionResultDTO;

    /** Adopts an existing historical claim. */
    public function adoptHistorical(AdoptHistoricalCommand $command): AdoptionResultDTO;

    /** Adopts an existing alias claim. */
    public function adoptAlias(AdoptAliasCommand $command): AdoptionResultDTO;

    /** Permanently purges an eligible binding and its owned records. */
    public function purgeBinding(PurgeBindingCommand $command): void;
}
