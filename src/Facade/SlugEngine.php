<?php

declare(strict_types=1);

namespace Maatify\Slug\Facade;

use Maatify\Persistence\Pdo\Pagination\PageResult;
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
use Maatify\Slug\Lifecycle\Management\Service\SlugManagementQueryInterface;
use Maatify\Slug\Lifecycle\Consumer\Service\SlugQueryServiceInterface;
use Maatify\Slug\Lifecycle\Service\SlugScopeRegistryInterface;
use Maatify\Slug\Canonicalization\Service\SlugTextServiceInterface;
use Maatify\Slug\Lifecycle\Management\Criteria\AliasCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\BindingCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\BindingSearchCriteria;
use Maatify\Slug\Lifecycle\Criteria\CurrentSlugCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\HistoryCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\RegistryCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\RegistrySearchCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\ScopeCriteria;
use Maatify\Slug\Lifecycle\Consumer\Criteria\AvailabilityCriteria;
use Maatify\Slug\Lifecycle\Consumer\Criteria\ResolutionCriteria;
use Maatify\Slug\Lifecycle\DTO\AdoptionResultDTO;
use Maatify\Slug\Lifecycle\DTO\AliasDTO;
use Maatify\Slug\Lifecycle\DTO\BindingDTO;
use Maatify\Slug\Canonicalization\DTO\CanonicalSlugDTO;
use Maatify\Slug\Lifecycle\DTO\CurrentSlugDTO;
use Maatify\Slug\Canonicalization\DTO\GeneratedSlugDTO;
use Maatify\Slug\Lifecycle\DTO\HistoryEventDTO;
use Maatify\Slug\Canonicalization\DTO\LookupCanonicalizationDTO;
use Maatify\Slug\Lifecycle\DTO\RegistryClaimDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\Consumer\DTO\SlugAvailabilityDTO;
use Maatify\Slug\Lifecycle\Consumer\DTO\SlugResolutionDTO;
use Maatify\Slug\Lifecycle\DTO\AtomicTransferResultDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeTransitionResultDTO;
use Maatify\Slug\Lifecycle\DTO\SlugMutationResultDTO;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Canonicalization\Contract\SlugProfileInterface;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistryInterface;

/**
 * Framework-neutral aggregate facade for canonicalization, lifecycle mutation,
 * consumer reads, and package-owned management reads.
 *
 * The facade delegates each contract to its injected service; it does not
 * introduce alternate persistence or canonicalization rules.
 */
final class SlugEngine implements SlugTextServiceInterface, SlugProfileRegistryInterface, SlugScopeRegistryInterface, SlugLifecycleServiceInterface, SlugQueryServiceInterface, SlugManagementQueryInterface
{
    /** The factory is the construction boundary for the fully wired aggregate. */
    private function __construct(
        private SlugTextServiceInterface $text,
        private SlugProfileRegistryInterface $profiles,
        private SlugScopeRegistryInterface $scopes,
        private SlugLifecycleServiceInterface $lifecycle,
        private SlugQueryServiceInterface $query,
        private SlugManagementQueryInterface $management,
    ) {}

    /** Generates a canonical slug from source text using the selected profile. */
    public function generateFromSource(SlugProfileKey $profile, string $source): GeneratedSlugDTO
    {
        return $this->text->generateFromSource($profile, $source);
    }
    /** Canonicalizes a candidate for an exact claim using the selected profile. */
    public function canonicalizeClaim(SlugProfileKey $profile, string $candidate): CanonicalSlugDTO
    {
        return $this->text->canonicalizeClaim($profile, $candidate);
    }
    /** Classifies a decoded lookup segment and returns its canonical form when valid. */
    public function canonicalizeLookup(SlugProfileKey $profile, string $decodedSegment): LookupCanonicalizationDTO
    {
        return $this->text->canonicalizeLookup($profile, $decodedSegment);
    }
    /** Registers a profile without replacing an existing profile with the same key. */
    public function register(SlugProfileInterface $profile): void
    {
        $this->profiles->register($profile);
    }
    /** Returns a registered profile or fails when the key is unknown. */
    public function get(SlugProfileKey $key): SlugProfileInterface
    {
        return $this->profiles->get($key);
    }
    /** Reports whether a profile key is registered. */
    public function has(SlugProfileKey $key): bool
    {
        return $this->profiles->has($key);
    }
    /** Ensures a package-owned scope exists for the requested profile identity. */
    public function ensureScope(ScopeProfileRequestDTO $request): ScopeDTO
    {
        return $this->scopes->ensureScope($request);
    }

    /** Delegates exact assignment without changing canonicalization, CAS, transaction, or replay semantics. */
    public function assignExact(AssignExactCommand $command): SlugMutationResultDTO
    {
        return $this->lifecycle->assignExact($command);
    }
    /** Delegates generated assignment without changing candidate, reservation, CAS, or replay semantics. */
    public function assignGenerated(AssignGeneratedCommand $command): SlugMutationResultDTO
    {
        return $this->lifecycle->assignGenerated($command);
    }
    /** Delegates exact change without changing history ownership, CAS, transaction, or replay semantics. */
    public function changeExact(ChangeExactCommand $command): SlugMutationResultDTO
    {
        return $this->lifecycle->changeExact($command);
    }
    /** Delegates generated change without changing candidate ordering, CAS, or transaction semantics. */
    public function changeGenerated(ChangeGeneratedCommand $command): SlugMutationResultDTO
    {
        return $this->lifecycle->changeGenerated($command);
    }
    /** Delegates historical restoration without changing role, CAS, transaction, or replay semantics. */
    public function restoreHistorical(RestoreHistoricalCommand $command): SlugMutationResultDTO
    {
        return $this->lifecycle->restoreHistorical($command);
    }
    /** Delegates deactivation without changing retained ownership, state preconditions, or CAS semantics. */
    public function deactivate(DeactivateBindingCommand $command): SlugMutationResultDTO
    {
        return $this->lifecycle->deactivate($command);
    }
    /** Delegates reactivation without changing current ownership, state preconditions, or CAS semantics. */
    public function reactivate(ReactivateBindingCommand $command): SlugMutationResultDTO
    {
        return $this->lifecycle->reactivate($command);
    }
    /** Delegates non-current claim release without changing history retention or CAS semantics. */
    public function releaseClaim(ReleaseClaimCommand $command): SlugMutationResultDTO
    {
        return $this->lifecycle->releaseClaim($command);
    }
    /** Delegates release-all without changing destructive ownership semantics, history, or replay behavior. */
    public function releaseAllOwnership(ReleaseAllOwnershipCommand $command): SlugMutationResultDTO
    {
        return $this->lifecycle->releaseAllOwnership($command);
    }
    /** Delegates MOVE/PARALLEL transition without changing claim intent, atomicity, CAS, or replay semantics. */
    public function transitionScope(TransitionScopeCommand $command): ScopeTransitionResultDTO
    {
        return $this->lifecycle->transitionScope($command);
    }
    /** Delegates atomic transfer without changing source/target CAS or replacement constraints. */
    public function atomicTransfer(AtomicTransferCommand $command): AtomicTransferResultDTO
    {
        return $this->lifecycle->atomicTransfer($command);
    }
    /** Delegates alias creation without changing role, reservation, CAS, or replay semantics. */
    public function addAlias(AddAliasCommand $command): SlugMutationResultDTO
    {
        return $this->lifecycle->addAlias($command);
    }
    /** Delegates alias retirement without changing role preconditions, history, or CAS semantics. */
    public function retireAlias(RetireAliasCommand $command): SlugMutationResultDTO
    {
        return $this->lifecycle->retireAlias($command);
    }
    /** Delegates alias reactivation without changing role preconditions, history, or CAS semantics. */
    public function reactivateAlias(ReactivateAliasCommand $command): SlugMutationResultDTO
    {
        return $this->lifecycle->reactivateAlias($command);
    }
    /** Delegates alias promotion without changing current/history ownership, CAS, or replay semantics. */
    public function promoteAliasToCurrent(PromoteAliasToCurrentCommand $command): SlugMutationResultDTO
    {
        return $this->lifecycle->promoteAliasToCurrent($command);
    }
    /** Delegates current-claim adoption without changing absent-revision, timestamp, or replay semantics. */
    public function adoptCurrent(AdoptCurrentCommand $command): AdoptionResultDTO
    {
        return $this->lifecycle->adoptCurrent($command);
    }
    /** Delegates historical adoption without changing role, timestamp, CAS, or replay semantics. */
    public function adoptHistorical(AdoptHistoricalCommand $command): AdoptionResultDTO
    {
        return $this->lifecycle->adoptHistorical($command);
    }
    /** Delegates alias adoption without changing role, timestamp, CAS, or replay semantics. */
    public function adoptAlias(AdoptAliasCommand $command): AdoptionResultDTO
    {
        return $this->lifecycle->adoptAlias($command);
    }
    /** Delegates destructive purge without changing RELEASED/zero-claims or CAS preconditions. */
    public function purgeBinding(PurgeBindingCommand $command): void
    {
        $this->lifecycle->purgeBinding($command);
    }

    /** Reports whether a canonical slug is available without changing package state. */
    public function checkAvailability(AvailabilityCriteria $criteria): SlugAvailabilityDTO
    {
        return $this->query->checkAvailability($criteria);
    }
    /** Returns the current claim for a binding, or null when no current claim exists. */
    public function getCurrent(CurrentSlugCriteria $criteria): ?CurrentSlugDTO
    {
        return $this->query->getCurrent($criteria);
    }
    /** Resolves a lookup segment without mutating package state. */
    public function resolve(ResolutionCriteria $criteria): SlugResolutionDTO
    {
        return $this->query->resolve($criteria);
    }

    /** Returns a package-owned binding snapshot, or null when it is absent. */
    public function getBinding(BindingCriteria $criteria): ?BindingDTO
    {
        return $this->management->getBinding($criteria);
    }
    /**
     * Returns a paginated view of a binding's alias claims.
     *
     * @return PageResult<AliasDTO>
     */
    public function listAliases(AliasCriteria $criteria): PageResult
    {
        return $this->management->listAliases($criteria);
    }
    /**
     * Returns a paginated history view for a binding.
     *
     * @return PageResult<HistoryEventDTO>
     */
    public function getHistory(HistoryCriteria $criteria): PageResult
    {
        return $this->management->getHistory($criteria);
    }
    /**
     * Returns paginated registry claims within the requested scope and filters.
     *
     * @return PageResult<RegistryClaimDTO>
     */
    public function inspectRegistry(RegistryCriteria $criteria): PageResult
    {
        return $this->management->inspectRegistry($criteria);
    }
    /** Returns the persisted scope snapshot, or null when the scope is absent. */
    public function inspectScope(ScopeCriteria $criteria): ?ScopeDTO
    {
        return $this->management->inspectScope($criteria);
    }
    /**
     * Returns paginated bindings matching the requested scope and search filters.
     *
     * @return PageResult<BindingDTO>
     */
    public function searchBindings(BindingSearchCriteria $criteria): PageResult
    {
        return $this->management->searchBindings($criteria);
    }
    /**
     * Returns paginated registry claims matching the requested search filters.
     *
     * @return PageResult<RegistryClaimDTO>
     */
    public function searchRegistry(RegistrySearchCriteria $criteria): PageResult
    {
        return $this->management->searchRegistry($criteria);
    }
}
