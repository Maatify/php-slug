<?php

declare(strict_types=1);

namespace Maatify\Slug\Management;

use Maatify\Persistence\Pdo\Pagination\PageResult;
use Maatify\Slug\Contract\SlugManagementQueryInterface;
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
use Maatify\Slug\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Exception\SlugScopeProfileMismatchException;
use Maatify\Slug\Infrastructure\Persistence\PDO\Connection\PdoCapabilityGuard;
use Maatify\Slug\Infrastructure\Persistence\PDO\Query\PdoSlugManagementQueryRepository;
use Maatify\Slug\Infrastructure\Persistence\PDO\Registry\PdoRegistryRepository;

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
