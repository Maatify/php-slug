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

/** Persistence boundary for scopes, bindings, claims, and their optimistic-lock state. */
interface RegistryRepositoryInterface
{
    /** Verifies that the installed package schema supports this repository contract. */
    public function assertInstalledSchemaSupported(): void;

    /** Ensures and returns a scope/profile association. */
    public function ensureScope(SlugScope $scope, SlugProfileKey $profileKey): ScopeDTO;

    /** Finds a scope/profile association, optionally with a row lock. */
    public function findScope(SlugScope $scope, SlugProfileKey $expectedProfileKey, bool $forUpdate = false): ?ScopeDTO;

    /** Locks an existing scope and fails if it is absent or profile-mismatched. */
    public function lockScope(SlugScope $scope, SlugProfileKey $expectedProfileKey): ScopeDTO;

    /** Locks or creates a binding row for an entity in a scope. */
    public function lockOrCreateBinding(ScopeDTO $scope, EntityReference $entity): RegistryBindingRecord;

    /** Alias of the explicit binding-row lock/create operation used by lifecycle services. */
    public function lockOrCreateBindingRecord(ScopeDTO $scope, EntityReference $entity): RegistryBindingRecord;

    /** Finds a binding row, optionally with a row lock. */
    public function findBindingRecord(ScopeDTO $scope, EntityReference $entity, bool $forUpdate = false): ?RegistryBindingRecord;

    /** Finds a claim by scope and canonical slug, optionally with a row lock. */
    public function findClaimByScopeSlug(int $scopeId, Slug $slug, bool $forUpdate = false): ?RegistryClaimRecord;

    /** Finds a claim by binding and canonical slug, optionally with a row lock. */
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

    /** Inserts a claim and returns its row, or null when the persistence race loses. */
    public function insertClaim(int $scopeId, int $bindingId, Slug $slug, RegistryRoleEnum $role): ?RegistryClaimRecord;

    /** Marks a registry claim current while applying the expected binding revision. */
    public function activateCurrent(int $bindingId, int $expectedRevision, int $registryId): void;

    /** Mutates binding status, current claim pointer, revision, and history sequence atomically. */
    public function mutateBinding(int $bindingId, int $expectedRevision, BindingStatusEnum $status, ?int $currentRegistryId, int $historySequence): void;

    /** Changes a claim role and returns its updated raw record. */
    public function updateClaimRole(int $registryId, RegistryRoleEnum $role): RegistryClaimRecord;

    /** Deletes a claim row after lifecycle rules have authorized the operation. */
    public function deleteClaim(int $registryId): void;

    /** Returns the public binding snapshot for an identity, optionally under lock. */
    public function binding(BindingIdentityDTO $identity, bool $forUpdate = false): ?BindingDTO;
}
