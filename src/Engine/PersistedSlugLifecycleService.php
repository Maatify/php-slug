<?php

declare(strict_types=1);

namespace Maatify\Slug\Engine;

use Maatify\Slug\Adoption\AdoptionService;
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
use Maatify\Slug\Contract\SlugLifecycleServiceInterface;
use Maatify\Slug\DTO\AdoptionResultDTO;
use Maatify\Slug\DTO\AtomicTransferResultDTO;
use Maatify\Slug\DTO\ScopeTransitionResultDTO;
use Maatify\Slug\DTO\SlugMutationResultDTO;
use Maatify\Slug\Lifecycle\SlugLifecycleService;
use Maatify\Slug\Transition\ScopeTransitionService;

/** Composite lifecycle adapter that closes the WU-05 and WU-06 surface. */
final readonly class PersistedSlugLifecycleService implements SlugLifecycleServiceInterface
{
    public function __construct(
        private SlugLifecycleService $legacyLifecycle,
        private ScopeTransitionService $transition,
        private AdoptionService $adoption,
    ) {}

    public function assignExact(AssignExactCommand $command): SlugMutationResultDTO
    {
        return $this->legacyLifecycle->assignExact($command);
    }
    public function assignGenerated(AssignGeneratedCommand $command): SlugMutationResultDTO
    {
        return $this->legacyLifecycle->assignGenerated($command);
    }
    public function changeExact(ChangeExactCommand $command): SlugMutationResultDTO
    {
        return $this->legacyLifecycle->changeExact($command);
    }
    public function changeGenerated(ChangeGeneratedCommand $command): SlugMutationResultDTO
    {
        return $this->legacyLifecycle->changeGenerated($command);
    }
    public function restoreHistorical(RestoreHistoricalCommand $command): SlugMutationResultDTO
    {
        return $this->legacyLifecycle->restoreHistorical($command);
    }
    public function deactivate(DeactivateBindingCommand $command): SlugMutationResultDTO
    {
        return $this->legacyLifecycle->deactivate($command);
    }
    public function reactivate(ReactivateBindingCommand $command): SlugMutationResultDTO
    {
        return $this->legacyLifecycle->reactivate($command);
    }
    public function releaseClaim(ReleaseClaimCommand $command): SlugMutationResultDTO
    {
        return $this->legacyLifecycle->releaseClaim($command);
    }
    public function releaseAllOwnership(ReleaseAllOwnershipCommand $command): SlugMutationResultDTO
    {
        return $this->legacyLifecycle->releaseAllOwnership($command);
    }
    public function transitionScope(TransitionScopeCommand $command): ScopeTransitionResultDTO
    {
        return $this->transition->transitionScope($command);
    }
    public function atomicTransfer(AtomicTransferCommand $command): AtomicTransferResultDTO
    {
        return $this->legacyLifecycle->atomicTransfer($command);
    }
    public function addAlias(AddAliasCommand $command): SlugMutationResultDTO
    {
        return $this->legacyLifecycle->addAlias($command);
    }
    public function retireAlias(RetireAliasCommand $command): SlugMutationResultDTO
    {
        return $this->legacyLifecycle->retireAlias($command);
    }
    public function reactivateAlias(ReactivateAliasCommand $command): SlugMutationResultDTO
    {
        return $this->legacyLifecycle->reactivateAlias($command);
    }
    public function promoteAliasToCurrent(PromoteAliasToCurrentCommand $command): SlugMutationResultDTO
    {
        return $this->legacyLifecycle->promoteAliasToCurrent($command);
    }
    public function adoptCurrent(AdoptCurrentCommand $command): AdoptionResultDTO
    {
        return $this->adoption->adoptCurrent($command);
    }
    public function adoptHistorical(AdoptHistoricalCommand $command): AdoptionResultDTO
    {
        return $this->adoption->adoptHistorical($command);
    }
    public function adoptAlias(AdoptAliasCommand $command): AdoptionResultDTO
    {
        return $this->adoption->adoptAlias($command);
    }
    public function purgeBinding(PurgeBindingCommand $command): void
    {
        $this->legacyLifecycle->purgeBinding($command);
    }
}
