<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Management\Repository;

use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Persistence\Pdo\Pagination\PageResult;
use Maatify\Slug\Lifecycle\DTO\AliasDTO;
use Maatify\Slug\Lifecycle\DTO\BindingDTO;
use Maatify\Slug\Lifecycle\DTO\HistoryEventDTO;
use Maatify\Slug\Lifecycle\DTO\RegistryClaimDTO;
use Maatify\Slug\Lifecycle\Enum\HistoryEventTypeEnum;
use Maatify\Slug\Lifecycle\Enum\RegistryRoleEnum;
use Maatify\Slug\Lifecycle\Management\Criteria\BindingSearchCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\RegistrySearchCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\ScopeSearchCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\HistorySearchCriteria;
use Maatify\Slug\Lifecycle\DTO\ScopeDTO;
use Maatify\Slug\Lifecycle\Management\DTO\ScopeOperationalSummaryDTO;

/** Read-only repository boundary for paginated management projections. */
interface ManagementQueryRepositoryInterface
{
    /**
     * Returns aliases for one binding.
     *
     * @return PageResult<AliasDTO>
     */
    public function listAliases(int $bindingId, PageRequest $request): PageResult;

    /**
     * Returns history for one binding, optionally filtered by event type.
     *
     * @return PageResult<HistoryEventDTO>
     */
    public function getHistory(int $bindingId, ?HistoryEventTypeEnum $eventType, PageRequest $request): PageResult;

    /**
     * Returns registry claims for a scope, optionally narrowed by binding and role.
     *
     * @return PageResult<RegistryClaimDTO>
     */
    public function inspectRegistry(int $scopeId, ?int $bindingId, ?RegistryRoleEnum $role, PageRequest $request): PageResult;

    /**
     * Returns bindings matching the supplied scope and filters.
     *
     * @return PageResult<BindingDTO>
     */
    public function searchBindings(int $scopeId, BindingSearchCriteria $criteria): PageResult;

    /**
     * Returns registry claims matching the supplied scope and filters.
     *
     * @return PageResult<RegistryClaimDTO>
     */
    public function searchRegistry(int $scopeId, RegistrySearchCriteria $criteria): PageResult;

    /**
     * Returns all persisted Scopes matching the supplied search criteria.
     *
     * @return PageResult<ScopeDTO>
     */
    public function searchScopes(ScopeSearchCriteria $criteria): PageResult;

    /** Returns persisted operational counts for one Scope. */
    public function getScopeOperationalSummary(ScopeDTO $scope): ScopeOperationalSummaryDTO;

    /**
     * Returns package history using persisted event Scope snapshots for attribution.
     *
     * @return PageResult<HistoryEventDTO>
     */
    public function searchHistory(HistorySearchCriteria $criteria): PageResult;
}
