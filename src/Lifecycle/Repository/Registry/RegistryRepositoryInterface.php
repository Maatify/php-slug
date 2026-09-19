<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Repository\Registry;

use Maatify\Slug\Canonicalization\ValueObject\Slug;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Lifecycle\DTO\BindingDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeDTO;
use Maatify\Slug\Lifecycle\Enum\BindingStatusEnum;
use Maatify\Slug\Lifecycle\Enum\RegistryRoleEnum;
use Maatify\Slug\Lifecycle\ValueObject\EntityReference;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;

interface RegistryRepositoryInterface
{
    public function assertInstalledSchemaSupported(): void;

    public function ensureScope(SlugScope $scope, SlugProfileKey $profileKey): ScopeDTO;

    public function findScope(SlugScope $scope, SlugProfileKey $expectedProfileKey, bool $forUpdate = false): ?ScopeDTO;

    public function lockScope(SlugScope $scope, SlugProfileKey $expectedProfileKey): ScopeDTO;

    public function lockOrCreateBinding(ScopeDTO $scope, EntityReference $entity): RegistryBindingRecord;

    public function lockOrCreateBindingRecord(ScopeDTO $scope, EntityReference $entity): RegistryBindingRecord;

    public function findBindingRecord(ScopeDTO $scope, EntityReference $entity, bool $forUpdate = false): ?RegistryBindingRecord;

    public function findClaimByScopeSlug(int $scopeId, Slug $slug, bool $forUpdate = false): ?RegistryClaimRecord;

    public function findClaimByBindingSlug(int $bindingId, Slug $slug, bool $forUpdate = false): ?RegistryClaimRecord;

    /** @return list<RegistryClaimRecord> */
    public function findClaimsForBinding(int $bindingId, bool $forUpdate = false): array;

    /**
     * @param list<int> $bindingIds
     * @return list<RegistryClaimRecord>
     */
    public function findClaimsForBindings(array $bindingIds, bool $forUpdate = false): array;

    /**
     * @param list<array{scope_id: int, slug: string}> $keys
     * @return list<RegistryClaimRecord>
     */
    public function findClaimsForKeys(array $keys, bool $forUpdate = false): array;

    /** @return list<RegistryClaimRecord> */
    public function findClaimsInScopeSlugRange(int $scopeId, string $minimumSlug, string $maximumSlug, bool $forUpdate = false): array;

    public function insertClaim(int $scopeId, int $bindingId, Slug $slug, RegistryRoleEnum $role): ?RegistryClaimRecord;

    public function activateCurrent(int $bindingId, int $expectedRevision, int $registryId): void;

    public function mutateBinding(int $bindingId, int $expectedRevision, BindingStatusEnum $status, ?int $currentRegistryId, int $historySequence): void;

    public function updateClaimRole(int $registryId, RegistryRoleEnum $role): RegistryClaimRecord;

    public function deleteClaim(int $registryId): void;

    public function binding(BindingIdentityDTO $identity, bool $forUpdate = false): ?BindingDTO;
}
