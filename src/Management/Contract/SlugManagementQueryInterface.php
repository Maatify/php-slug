<?php

declare(strict_types=1);

namespace Maatify\Slug\Management\Contract;

use Maatify\Persistence\Pdo\Pagination\PageResult;
use Maatify\Slug\Management\Criteria\AliasCriteria;
use Maatify\Slug\Management\Criteria\BindingCriteria;
use Maatify\Slug\Management\Criteria\BindingSearchCriteria;
use Maatify\Slug\Query\Criteria\CurrentSlugCriteria;
use Maatify\Slug\Management\Criteria\HistoryCriteria;
use Maatify\Slug\Management\Criteria\RegistryCriteria;
use Maatify\Slug\Management\Criteria\RegistrySearchCriteria;
use Maatify\Slug\Management\Criteria\ScopeCriteria;
use Maatify\Slug\Registry\DTO\AliasDTO;
use Maatify\Slug\Registry\DTO\BindingDTO;
use Maatify\Slug\Query\DTO\CurrentSlugDTO;
use Maatify\Slug\Lifecycle\History\HistoryEventDTO;
use Maatify\Slug\Registry\DTO\RegistryClaimDTO;
use Maatify\Slug\Scope\DTO\ScopeDTO;

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
