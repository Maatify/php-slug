<?php

declare(strict_types=1);

namespace Maatify\Slug\Contract;

use Maatify\Persistence\Pdo\Pagination\PageResult;
use Maatify\Slug\Criteria\AliasCriteria;
use Maatify\Slug\Criteria\BindingCriteria;
use Maatify\Slug\Criteria\BindingSearchCriteria;
use Maatify\Slug\Criteria\CurrentSlugCriteria;
use Maatify\Slug\Criteria\HistoryCriteria;
use Maatify\Slug\Criteria\RegistryCriteria;
use Maatify\Slug\Criteria\RegistrySearchCriteria;
use Maatify\Slug\Criteria\ScopeCriteria;
use Maatify\Slug\DTO\AliasDTO;
use Maatify\Slug\DTO\BindingDTO;
use Maatify\Slug\DTO\CurrentSlugDTO;
use Maatify\Slug\DTO\HistoryEventDTO;
use Maatify\Slug\DTO\RegistryClaimDTO;
use Maatify\Slug\DTO\ScopeDTO;

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
