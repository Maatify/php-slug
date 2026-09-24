<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Management\Service;

use Maatify\Persistence\Pdo\Pagination\PageResult;
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
use Maatify\Slug\Lifecycle\DTO\ScopeOperationalSummaryDTO;

/** Read-only management boundary for package-owned binding, registry, and history data. */
interface SlugManagementQueryInterface
{
    /** Returns a binding snapshot or null when the requested binding is absent. */
    public function getBinding(BindingCriteria $criteria): ?BindingDTO;

    /** Returns the current claim snapshot or null when no current claim exists. */
    public function getCurrent(CurrentSlugCriteria $criteria): ?CurrentSlugDTO;

    /**
     * Returns a page of alias claims for the requested binding.
     *
     * @return PageResult<AliasDTO>
     */
    public function listAliases(AliasCriteria $criteria): PageResult;

    /**
     * Returns a page of lifecycle history events for the requested binding.
     *
     * @return PageResult<HistoryEventDTO>
     */
    public function getHistory(HistoryCriteria $criteria): PageResult;

    /**
     * Returns a page of registry claims within the requested scope and filters.
     *
     * @return PageResult<RegistryClaimDTO>
     */
    public function inspectRegistry(RegistryCriteria $criteria): PageResult;

    /** Returns a scope snapshot or null when the requested scope is absent. */
    public function inspectScope(ScopeCriteria $criteria): ?ScopeDTO;

    /**
     * Returns persisted package Scopes matching the optional literal filters.
     *
     * @return PageResult<ScopeDTO>
     */
    public function searchScopes(ScopeSearchCriteria $criteria): PageResult;

    /** Returns operational counts for a Scope, or null when the Scope is absent. */
    public function getScopeOperationalSummary(ScopeCriteria $criteria): ?ScopeOperationalSummaryDTO;

    /**
     * Returns package history, optionally narrowed to an exact persisted Scope snapshot.
     *
     * @return PageResult<HistoryEventDTO>
     */
    public function searchHistory(HistorySearchCriteria $criteria): PageResult;

    /**
     * Returns a page of bindings matching the requested search criteria.
     *
     * @return PageResult<BindingDTO>
     */
    public function searchBindings(BindingSearchCriteria $criteria): PageResult;

    /**
     * Returns a page of registry claims matching the requested search criteria.
     *
     * @return PageResult<RegistryClaimDTO>
     */
    public function searchRegistry(RegistrySearchCriteria $criteria): PageResult;
}
