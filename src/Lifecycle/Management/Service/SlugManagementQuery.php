<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Management\Service;

use Maatify\Persistence\Pdo\Pagination\PageResult;
use Maatify\Slug\Lifecycle\Management\Service\SlugManagementQueryInterface;
use Maatify\Slug\Lifecycle\Management\Criteria\AliasCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\BindingCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\BindingSearchCriteria;
use Maatify\Slug\Lifecycle\Criteria\CurrentSlugCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\HistoryCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\RegistryCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\RegistrySearchCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\ScopeCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\ScopeSearchCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\HistorySearchCriteria;
use Maatify\Slug\Lifecycle\DTO\AliasDTO;
use Maatify\Slug\Lifecycle\DTO\BindingDTO;
use Maatify\Slug\Lifecycle\DTO\CurrentSlugDTO;
use Maatify\Slug\Lifecycle\DTO\HistoryEventDTO;
use Maatify\Slug\Lifecycle\DTO\RegistryClaimDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\Management\DTO\ScopeOperationalSummaryDTO;
use Maatify\Slug\Lifecycle\Exception\SlugScopeProfileMismatchException;
use Maatify\Slug\Lifecycle\Repository\CapabilityGuardInterface;
use Maatify\Slug\Lifecycle\Management\Repository\ManagementQueryRepositoryInterface;
use Maatify\Slug\Lifecycle\Repository\Registry\RegistryRepositoryInterface;

/** Public read-only adapter for package-owned management and operational data. */
final readonly class SlugManagementQuery implements SlugManagementQueryInterface
{
    /** Uses package-owned repositories and verifies the installed schema before each read. */
    public function __construct(
        private RegistryRepositoryInterface $registry,
        private ManagementQueryRepositoryInterface $queries,
        private CapabilityGuardInterface $capabilities,
    ) {}

    /** Returns a binding snapshot or null when no matching binding exists. */
    public function getBinding(BindingCriteria $criteria): ?BindingDTO
    {
        $this->capabilities->assertInstalledSchemaSupported();
        return $this->registry->binding($criteria->binding);
    }

    /** Returns a binding's current claim and revision, or null when no current claim exists. */
    public function getCurrent(CurrentSlugCriteria $criteria): ?CurrentSlugDTO
    {
        $this->capabilities->assertInstalledSchemaSupported();
        $binding = $this->registry->binding($criteria->binding);
        if ($binding === null || $binding->currentClaim === null) {
            return null;
        }

        return new CurrentSlugDTO($binding, $binding->currentClaim, $binding->state->revision);
    }

    /**
     * Returns a page of aliases; an absent binding produces an empty result page.
     *
     * @return PageResult<AliasDTO>
     */
    public function listAliases(AliasCriteria $criteria): PageResult
    {
        $this->capabilities->assertInstalledSchemaSupported();
        $binding = $this->registry->binding($criteria->binding);
        return $this->queries->listAliases($binding === null ? -1 : $binding->id, $criteria->pageRequest);
    }

    /**
     * Returns a page of history events; an absent binding produces an empty result page.
     *
     * @return PageResult<HistoryEventDTO>
     */
    public function getHistory(HistoryCriteria $criteria): PageResult
    {
        $this->capabilities->assertInstalledSchemaSupported();
        $binding = $this->registry->binding($criteria->binding);
        return $this->queries->getHistory($binding === null ? -1 : $binding->id, $criteria->eventType, $criteria->pageRequest);
    }

    /**
     * Returns scoped registry claims and rejects a binding from another scope/profile.
     *
     * @return PageResult<RegistryClaimDTO>
     */
    public function inspectRegistry(RegistryCriteria $criteria): PageResult
    {
        $this->capabilities->assertInstalledSchemaSupported();
        if ($criteria->binding !== null && ! $this->sameScopeProfile($criteria->scopeProfile, $criteria->binding->scopeProfile)) {
            throw new SlugScopeProfileMismatchException('Registry criteria Binding does not belong to the requested Scope.');
        }
        $scope = $this->registry->findScope($criteria->scopeProfile->scope, $criteria->scopeProfile->expectedProfileKey);
        $binding = $criteria->binding === null ? null : $this->registry->binding($criteria->binding);
        if ($binding !== null && ! $this->sameScopeProfile($criteria->scopeProfile, $binding->identity->scopeProfile)) {
            throw new SlugScopeProfileMismatchException('Registry criteria Binding does not belong to the requested Scope.');
        }
        return $this->queries->inspectRegistry($scope === null ? -1 : $scope->id, $binding === null ? null : $binding->id, $criteria->role, $criteria->pageRequest);
    }

    /** Returns a matching scope snapshot or null when it has not been persisted. */
    public function inspectScope(ScopeCriteria $criteria): ?ScopeDTO
    {
        $this->capabilities->assertInstalledSchemaSupported();
        return $this->registry->findScope($criteria->scopeProfile->scope, $criteria->scopeProfile->expectedProfileKey);
    }

    /**
     * Returns persisted package Scopes matching the optional search filters.
     *
     * @return PageResult<ScopeDTO>
     */
    public function searchScopes(ScopeSearchCriteria $criteria): PageResult
    {
        $this->capabilities->assertInstalledSchemaSupported();
        return $this->queries->searchScopes($criteria);
    }

    /** Returns Scope-local operational counts, or null when the Scope is absent. */
    public function getScopeOperationalSummary(ScopeCriteria $criteria): ?ScopeOperationalSummaryDTO
    {
        $this->capabilities->assertInstalledSchemaSupported();
        $scope = $this->registry->findScope($criteria->scopeProfile->scope, $criteria->scopeProfile->expectedProfileKey);
        return $scope === null ? null : $this->queries->getScopeOperationalSummary($scope);
    }

    /**
     * Returns operational History using the event's persisted Scope snapshot.
     *
     * @return PageResult<HistoryEventDTO>
     */
    public function searchHistory(HistorySearchCriteria $criteria): PageResult
    {
        $this->capabilities->assertInstalledSchemaSupported();
        if ($criteria->scopeProfile !== null) {
            $this->registry->findScope($criteria->scopeProfile->scope, $criteria->scopeProfile->expectedProfileKey);
        }

        return $this->queries->searchHistory($criteria);
    }

    /**
     * Returns bindings in the requested scope that match the search filters.
     *
     * @return PageResult<BindingDTO>
     */
    public function searchBindings(BindingSearchCriteria $criteria): PageResult
    {
        $this->capabilities->assertInstalledSchemaSupported();
        $scope = $this->registry->findScope($criteria->scopeProfile->scope, $criteria->scopeProfile->expectedProfileKey);
        return $this->queries->searchBindings($scope === null ? -1 : $scope->id, $criteria);
    }

    /**
     * Returns registry claims in the requested scope that match the search filters.
     *
     * @return PageResult<RegistryClaimDTO>
     */
    public function searchRegistry(RegistrySearchCriteria $criteria): PageResult
    {
        $this->capabilities->assertInstalledSchemaSupported();
        $scope = $this->registry->findScope($criteria->scopeProfile->scope, $criteria->scopeProfile->expectedProfileKey);
        return $this->queries->searchRegistry($scope === null ? -1 : $scope->id, $criteria);
    }

    /** Compares all scope dimensions and the expected profile key. */
    private function sameScopeProfile(ScopeProfileRequestDTO $left, ScopeProfileRequestDTO $right): bool
    {
        return $left->expectedProfileKey->value === $right->expectedProfileKey->value
            && $left->scope->namespace === $right->scope->namespace
            && $left->scope->localeKey === $right->scope->localeKey
            && $left->scope->contextKey === $right->scope->contextKey;
    }
}
