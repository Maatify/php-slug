<?php

declare(strict_types=1);

namespace Maatify\Slug\Engine;

use Maatify\Persistence\Pdo\Pagination\PageResult;
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
use Maatify\Slug\Contract\SlugManagementQueryInterface;
use Maatify\Slug\Contract\SlugQueryServiceInterface;
use Maatify\Slug\Contract\SlugScopeRegistryInterface;
use Maatify\Slug\Contract\SlugTextServiceInterface;
use Maatify\Slug\Criteria\AliasCriteria;
use Maatify\Slug\Criteria\BindingCriteria;
use Maatify\Slug\Criteria\BindingSearchCriteria;
use Maatify\Slug\Criteria\CurrentSlugCriteria;
use Maatify\Slug\Criteria\HistoryCriteria;
use Maatify\Slug\Criteria\RegistryCriteria;
use Maatify\Slug\Criteria\RegistrySearchCriteria;
use Maatify\Slug\Criteria\ScopeCriteria;
use Maatify\Slug\Criteria\AvailabilityCriteria;
use Maatify\Slug\Criteria\ResolutionCriteria;
use Maatify\Slug\DTO\AdoptionResultDTO;
use Maatify\Slug\DTO\AliasDTO;
use Maatify\Slug\DTO\BindingDTO;
use Maatify\Slug\DTO\CanonicalSlugDTO;
use Maatify\Slug\DTO\CurrentSlugDTO;
use Maatify\Slug\DTO\GeneratedSlugDTO;
use Maatify\Slug\DTO\HistoryEventDTO;
use Maatify\Slug\DTO\LookupCanonicalizationDTO;
use Maatify\Slug\DTO\RegistryClaimDTO;
use Maatify\Slug\DTO\ScopeDTO;
use Maatify\Slug\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\DTO\SlugAvailabilityDTO;
use Maatify\Slug\DTO\SlugResolutionDTO;
use Maatify\Slug\DTO\AtomicTransferResultDTO;
use Maatify\Slug\DTO\ScopeTransitionResultDTO;
use Maatify\Slug\DTO\SlugMutationResultDTO;
use Maatify\Slug\Identity\SlugProfileKey;
use Maatify\Slug\Profile\Contracts\SlugProfileInterface;
use Maatify\Slug\Profile\Contracts\SlugProfileRegistryInterface;

/** The complete framework-neutral RC1 aggregate facade. */
final class SlugEngine implements SlugTextServiceInterface, SlugProfileRegistryInterface, SlugScopeRegistryInterface, SlugLifecycleServiceInterface, SlugQueryServiceInterface, SlugManagementQueryInterface
{
    private function __construct(
        private SlugTextServiceInterface $text,
        private SlugProfileRegistryInterface $profiles,
        private SlugScopeRegistryInterface $scopes,
        private SlugLifecycleServiceInterface $lifecycle,
        private SlugQueryServiceInterface $query,
        private SlugManagementQueryInterface $management,
    ) {}

    public function generateFromSource(SlugProfileKey $profile, string $source): GeneratedSlugDTO
    {
        return $this->text->generateFromSource($profile, $source);
    }
    public function canonicalizeClaim(SlugProfileKey $profile, string $candidate): CanonicalSlugDTO
    {
        return $this->text->canonicalizeClaim($profile, $candidate);
    }
    public function canonicalizeLookup(SlugProfileKey $profile, string $decodedSegment): LookupCanonicalizationDTO
    {
        return $this->text->canonicalizeLookup($profile, $decodedSegment);
    }
    public function register(SlugProfileInterface $profile): void
    {
        $this->profiles->register($profile);
    }
    public function get(SlugProfileKey $key): SlugProfileInterface
    {
        return $this->profiles->get($key);
    }
    public function has(SlugProfileKey $key): bool
    {
        return $this->profiles->has($key);
    }
    public function ensureScope(ScopeProfileRequestDTO $request): ScopeDTO
    {
        return $this->scopes->ensureScope($request);
    }

    public function assignExact(AssignExactCommand $command): SlugMutationResultDTO
    {
        return $this->lifecycle->assignExact($command);
    }
    public function assignGenerated(AssignGeneratedCommand $command): SlugMutationResultDTO
    {
        return $this->lifecycle->assignGenerated($command);
    }
    public function changeExact(ChangeExactCommand $command): SlugMutationResultDTO
    {
        return $this->lifecycle->changeExact($command);
    }
    public function changeGenerated(ChangeGeneratedCommand $command): SlugMutationResultDTO
    {
        return $this->lifecycle->changeGenerated($command);
    }
    public function restoreHistorical(RestoreHistoricalCommand $command): SlugMutationResultDTO
    {
        return $this->lifecycle->restoreHistorical($command);
    }
    public function deactivate(DeactivateBindingCommand $command): SlugMutationResultDTO
    {
        return $this->lifecycle->deactivate($command);
    }
    public function reactivate(ReactivateBindingCommand $command): SlugMutationResultDTO
    {
        return $this->lifecycle->reactivate($command);
    }
    public function releaseClaim(ReleaseClaimCommand $command): SlugMutationResultDTO
    {
        return $this->lifecycle->releaseClaim($command);
    }
    public function releaseAllOwnership(ReleaseAllOwnershipCommand $command): SlugMutationResultDTO
    {
        return $this->lifecycle->releaseAllOwnership($command);
    }
    public function transitionScope(TransitionScopeCommand $command): ScopeTransitionResultDTO
    {
        return $this->lifecycle->transitionScope($command);
    }
    public function atomicTransfer(AtomicTransferCommand $command): AtomicTransferResultDTO
    {
        return $this->lifecycle->atomicTransfer($command);
    }
    public function addAlias(AddAliasCommand $command): SlugMutationResultDTO
    {
        return $this->lifecycle->addAlias($command);
    }
    public function retireAlias(RetireAliasCommand $command): SlugMutationResultDTO
    {
        return $this->lifecycle->retireAlias($command);
    }
    public function reactivateAlias(ReactivateAliasCommand $command): SlugMutationResultDTO
    {
        return $this->lifecycle->reactivateAlias($command);
    }
    public function promoteAliasToCurrent(PromoteAliasToCurrentCommand $command): SlugMutationResultDTO
    {
        return $this->lifecycle->promoteAliasToCurrent($command);
    }
    public function adoptCurrent(AdoptCurrentCommand $command): AdoptionResultDTO
    {
        return $this->lifecycle->adoptCurrent($command);
    }
    public function adoptHistorical(AdoptHistoricalCommand $command): AdoptionResultDTO
    {
        return $this->lifecycle->adoptHistorical($command);
    }
    public function adoptAlias(AdoptAliasCommand $command): AdoptionResultDTO
    {
        return $this->lifecycle->adoptAlias($command);
    }
    public function purgeBinding(PurgeBindingCommand $command): void
    {
        $this->lifecycle->purgeBinding($command);
    }

    public function checkAvailability(AvailabilityCriteria $criteria): SlugAvailabilityDTO
    {
        return $this->query->checkAvailability($criteria);
    }
    public function getCurrent(CurrentSlugCriteria $criteria): ?CurrentSlugDTO
    {
        return $this->query->getCurrent($criteria);
    }
    public function resolve(ResolutionCriteria $criteria): SlugResolutionDTO
    {
        return $this->query->resolve($criteria);
    }

    public function getBinding(BindingCriteria $criteria): ?BindingDTO
    {
        return $this->management->getBinding($criteria);
    }
    public function listAliases(AliasCriteria $criteria): PageResult
    {
        return $this->management->listAliases($criteria);
    }
    public function getHistory(HistoryCriteria $criteria): PageResult
    {
        return $this->management->getHistory($criteria);
    }
    public function inspectRegistry(RegistryCriteria $criteria): PageResult
    {
        return $this->management->inspectRegistry($criteria);
    }
    public function inspectScope(ScopeCriteria $criteria): ?ScopeDTO
    {
        return $this->management->inspectScope($criteria);
    }
    public function searchBindings(BindingSearchCriteria $criteria): PageResult
    {
        return $this->management->searchBindings($criteria);
    }
    public function searchRegistry(RegistrySearchCriteria $criteria): PageResult
    {
        return $this->management->searchRegistry($criteria);
    }
}
