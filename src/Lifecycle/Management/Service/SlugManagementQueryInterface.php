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
use Maatify\Slug\Lifecycle\DTO\AliasDTO;
use Maatify\Slug\Lifecycle\DTO\BindingDTO;
use Maatify\Slug\Lifecycle\DTO\CurrentSlugDTO;
use Maatify\Slug\Lifecycle\DTO\HistoryEventDTO;
use Maatify\Slug\Lifecycle\DTO\RegistryClaimDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeDTO;

interface SlugManagementQueryInterface
{
    public function getBinding(BindingCriteria $criteria): ?BindingDTO;

    public function getCurrent(CurrentSlugCriteria $criteria): ?CurrentSlugDTO;

    /** @return PageResult<AliasDTO> */
    public function listAliases(AliasCriteria $criteria): PageResult;

    /** @return PageResult<HistoryEventDTO> */
    public function getHistory(HistoryCriteria $criteria): PageResult;

    /** @return PageResult<RegistryClaimDTO> */
    public function inspectRegistry(RegistryCriteria $criteria): PageResult;

    public function inspectScope(ScopeCriteria $criteria): ?ScopeDTO;

    /** @return PageResult<BindingDTO> */
    public function searchBindings(BindingSearchCriteria $criteria): PageResult;

    /** @return PageResult<RegistryClaimDTO> */
    public function searchRegistry(RegistrySearchCriteria $criteria): PageResult;
}
