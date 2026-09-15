<?php

declare(strict_types=1);

namespace Maatify\Slug\Contract;

use Maatify\Slug\Command\AddAliasCommand;
use Maatify\Slug\Command\AdoptAliasCommand;
use Maatify\Slug\Command\AdoptCurrentCommand;
use Maatify\Slug\Command\AdoptHistoricalCommand;
use Maatify\Slug\Command\AssignExactCommand;
use Maatify\Slug\Command\AssignGeneratedCommand;
use Maatify\Slug\Command\AtomicTransferCommand;
use Maatify\Slug\Command\ChangeExactCommand;
use Maatify\Slug\Command\ChangeGeneratedCommand;
use Maatify\Slug\Command\DeactivateBindingCommand;
use Maatify\Slug\Command\PurgeBindingCommand;
use Maatify\Slug\Command\ReactivateAliasCommand;
use Maatify\Slug\Command\ReactivateBindingCommand;
use Maatify\Slug\Command\ReleaseAllOwnershipCommand;
use Maatify\Slug\Command\ReleaseClaimCommand;
use Maatify\Slug\Command\PromoteAliasToCurrentCommand;
use Maatify\Slug\Command\RestoreHistoricalCommand;
use Maatify\Slug\Command\RetireAliasCommand;
use Maatify\Slug\Command\TransitionScopeCommand;
use Maatify\Slug\DTO\AdoptionResultDTO;
use Maatify\Slug\DTO\AtomicTransferResultDTO;
use Maatify\Slug\DTO\ScopeTransitionResultDTO;
use Maatify\Slug\DTO\SlugMutationResultDTO;

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
