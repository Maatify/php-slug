<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Contract;

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

interface SlugLifecycleServiceInterface
{
    public function assignExact(AssignExactCommand $command): SlugMutationResultDTO;

    public function assignGenerated(AssignGeneratedCommand $command): SlugMutationResultDTO;

    public function changeExact(ChangeExactCommand $command): SlugMutationResultDTO;

    public function changeGenerated(ChangeGeneratedCommand $command): SlugMutationResultDTO;

    public function restoreHistorical(RestoreHistoricalCommand $command): SlugMutationResultDTO;

    public function deactivate(DeactivateBindingCommand $command): SlugMutationResultDTO;

    public function reactivate(ReactivateBindingCommand $command): SlugMutationResultDTO;

    public function releaseClaim(ReleaseClaimCommand $command): SlugMutationResultDTO;

    public function releaseAllOwnership(ReleaseAllOwnershipCommand $command): SlugMutationResultDTO;

    public function transitionScope(TransitionScopeCommand $command): ScopeTransitionResultDTO;

    public function atomicTransfer(AtomicTransferCommand $command): AtomicTransferResultDTO;

    public function addAlias(AddAliasCommand $command): SlugMutationResultDTO;

    public function retireAlias(RetireAliasCommand $command): SlugMutationResultDTO;

    public function reactivateAlias(ReactivateAliasCommand $command): SlugMutationResultDTO;

    public function promoteAliasToCurrent(PromoteAliasToCurrentCommand $command): SlugMutationResultDTO;

    public function adoptCurrent(AdoptCurrentCommand $command): AdoptionResultDTO;

    public function adoptHistorical(AdoptHistoricalCommand $command): AdoptionResultDTO;

    public function adoptAlias(AdoptAliasCommand $command): AdoptionResultDTO;

    public function purgeBinding(PurgeBindingCommand $command): void;
}
