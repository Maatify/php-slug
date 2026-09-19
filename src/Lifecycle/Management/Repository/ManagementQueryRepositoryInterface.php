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

interface ManagementQueryRepositoryInterface
{
    /** @return PageResult<AliasDTO> */
    public function listAliases(int $bindingId, PageRequest $request): PageResult;

    /** @return PageResult<HistoryEventDTO> */
    public function getHistory(int $bindingId, ?HistoryEventTypeEnum $eventType, PageRequest $request): PageResult;

    /** @return PageResult<RegistryClaimDTO> */
    public function inspectRegistry(int $scopeId, ?int $bindingId, ?RegistryRoleEnum $role, PageRequest $request): PageResult;

    /** @return PageResult<BindingDTO> */
    public function searchBindings(int $scopeId, BindingSearchCriteria $criteria): PageResult;

    /** @return PageResult<RegistryClaimDTO> */
    public function searchRegistry(int $scopeId, RegistrySearchCriteria $criteria): PageResult;
}
