<?php

declare(strict_types=1);

namespace Maatify\Slug\Management;

use Maatify\Persistence\Pdo\Pagination\PageResult;
use Maatify\Slug\Management\Contract\SlugManagementQueryInterface;
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
use Maatify\Slug\Scope\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Exception\SlugScopeProfileMismatchException;
use Maatify\Slug\Persistence\PDO\Connection\PdoCapabilityGuard;
use Maatify\Slug\Persistence\PDO\Query\PdoSlugManagementQueryRepository;
use Maatify\Slug\Persistence\PDO\Registry\PdoRegistryRepository;

/** Public management-read boundary for package-owned data. */
final readonly class SlugManagementQuery implements SlugManagementQueryInterface
{
    public function __construct(
        private PdoRegistryRepository $registry,
        private PdoSlugManagementQueryRepository $queries,
        private PdoCapabilityGuard $capabilities,
    ) {}

    public function getBinding(BindingCriteria $criteria): ?BindingDTO
    {
        $this->capabilities->assertInstalledSchemaSupported();
        return $this->registry->binding($criteria->binding);
    }

    public function getCurrent(CurrentSlugCriteria $criteria): ?CurrentSlugDTO
    {
        $this->capabilities->assertInstalledSchemaSupported();
        $binding = $this->registry->binding($criteria->binding);
        if ($binding === null || $binding->currentClaim === null) {
            return null;
        }

        return new CurrentSlugDTO($binding, $binding->currentClaim, $binding->state->revision);
    }

    /** @return PageResult<AliasDTO> */
    public function listAliases(AliasCriteria $criteria): PageResult
    {
        $this->capabilities->assertInstalledSchemaSupported();
        $binding = $this->registry->binding($criteria->binding);
        return $this->queries->listAliases($binding === null ? -1 : $binding->id, $criteria->pageRequest);
    }

    /** @return PageResult<HistoryEventDTO> */
    public function getHistory(HistoryCriteria $criteria): PageResult
    {
        $this->capabilities->assertInstalledSchemaSupported();
        $binding = $this->registry->binding($criteria->binding);
        return $this->queries->getHistory($binding === null ? -1 : $binding->id, $criteria->eventType, $criteria->pageRequest);
    }

    /** @return PageResult<RegistryClaimDTO> */
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

    public function inspectScope(ScopeCriteria $criteria): ?ScopeDTO
    {
        $this->capabilities->assertInstalledSchemaSupported();
        return $this->registry->findScope($criteria->scopeProfile->scope, $criteria->scopeProfile->expectedProfileKey);
    }

    /** @return PageResult<BindingDTO> */
    public function searchBindings(BindingSearchCriteria $criteria): PageResult
    {
        $this->capabilities->assertInstalledSchemaSupported();
        $scope = $this->registry->findScope($criteria->scopeProfile->scope, $criteria->scopeProfile->expectedProfileKey);
        return $this->queries->searchBindings($scope === null ? -1 : $scope->id, $criteria);
    }

    /** @return PageResult<RegistryClaimDTO> */
    public function searchRegistry(RegistrySearchCriteria $criteria): PageResult
    {
        $this->capabilities->assertInstalledSchemaSupported();
        $scope = $this->registry->findScope($criteria->scopeProfile->scope, $criteria->scopeProfile->expectedProfileKey);
        return $this->queries->searchRegistry($scope === null ? -1 : $scope->id, $criteria);
    }

    private function sameScopeProfile(ScopeProfileRequestDTO $left, ScopeProfileRequestDTO $right): bool
    {
        return $left->expectedProfileKey->value === $right->expectedProfileKey->value
            && $left->scope->namespace === $right->scope->namespace
            && $left->scope->localeKey === $right->scope->localeKey
            && $left->scope->contextKey === $right->scope->contextKey;
    }
}
