<?php

declare(strict_types=1);

namespace Maatify\Slug\Persistence\PDO\Registry;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Slug\Registry\DTO\BindingDTO;
use Maatify\Slug\Registry\DTO\BindingIdentityDTO;
use Maatify\Slug\Scope\DTO\ScopeDTO;
use Maatify\Slug\Scope\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Registry\Enum\BindingStatusEnum;
use Maatify\Slug\Registry\Enum\RegistryRoleEnum;
use Maatify\Slug\Exception\SlugPersistenceInvariantException;
use Maatify\Slug\Exception\SlugRevisionConflictException;
use Maatify\Slug\Exception\SlugScopeProfileMismatchException;
use Maatify\Slug\Registry\Value\EntityReference;
use Maatify\Slug\Text\Value\Slug;
use Maatify\Slug\Profile\Value\SlugProfileKey;
use Maatify\Slug\Persistence\PDO\Connection\PdoCapabilityGuard;
use Maatify\Slug\Persistence\PDO\Connection\PdoDuplicateClassifier;
use Maatify\Slug\Persistence\PDO\Connection\PdoRowHydrator;
use Maatify\Slug\Persistence\Contract\ScopePersistenceInterface;
use Maatify\Slug\Profile\Contract\SlugProfileRegistryInterface;
use Maatify\Slug\Scope\Value\SlugScope;

/**
 * Internal direct-PDO persistence for live Registry ownership.
 *
 * This repository deliberately exposes no public package contract. Lifecycle
 * code owns the public boundary and supplies its own transaction boundary.
 */
final readonly class PdoRegistryRepository
{
    private PdoCapabilityGuard $capabilities;

    public function __construct(
        private PDO $pdo,
        private SlugProfileRegistryInterface $profiles,
        private ScopePersistenceInterface $scopes,
        private ClockInterface $clock,
        ?PdoCapabilityGuard $capabilities = null,
    ) {
        $this->capabilities = $capabilities ?? new PdoCapabilityGuard($pdo);
    }

    public function assertInstalledSchemaSupported(): void
    {
        $this->capabilities->assertInstalledSchemaSupported();
    }

    public function ensureScope(SlugScope $scope, SlugProfileKey $profileKey): ScopeDTO
    {
        return $this->scopes->ensureScope(new ScopeProfileRequestDTO($scope, $profileKey));
    }

    public function findScope(SlugScope $scope, SlugProfileKey $expectedProfileKey, bool $forUpdate = false): ?ScopeDTO
    {
        $this->profiles->get($expectedProfileKey);
        $row = $this->findScopeRow($scope, $forUpdate);
        if ($row === null) {
            return null;
        }
        if (PdoRowHydrator::string($row, 'profile_key') !== $expectedProfileKey->value) {
            throw new SlugScopeProfileMismatchException('The existing Scope uses a different slug profile.');
        }

        return $this->scopeFromRow($row);
    }

    public function lockScope(SlugScope $scope, SlugProfileKey $expectedProfileKey): ScopeDTO
    {
        $result = $this->findScope($scope, $expectedProfileKey, true);
        if ($result === null) {
            throw new SlugPersistenceInvariantException('Scope disappeared before Registry mutation.');
        }

        return $result;
    }

    public function lockOrCreateBinding(ScopeDTO $scope, EntityReference $entity): RegistryBindingRecord
    {
        $record = $this->lockOrCreateBindingRecord($scope, $entity);

        return $this->validatedBindingRecord($scope, $entity, $record->createdInCurrentTransaction);
    }

    /**
     * Lock or create a Binding row without hydrating or locking Registry claims.
     *
     * Transition and adoption use this primitive to finish the global Binding
     * phase before constructing and acquiring their Registry lock plan.
     */
    public function lockOrCreateBindingRecord(ScopeDTO $scope, EntityReference $entity): RegistryBindingRecord
    {
        $row = $this->findBindingRow($scope->id, $entity, true);
        if ($row !== null) {
            return $this->bindingRecordFromRow($row, false);
        }

        $now = $this->timestamp();
        $created = false;
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO maa_slug_bindings '
                . '(scope_id, entity_type, entity_key, current_registry_id, status, revision, history_sequence, created_at, updated_at) '
                . 'VALUES (:scope_id, :entity_type, :entity_key, NULL, :status, :revision, :history_sequence, :created_at, :updated_at)',
            );
            if ($statement === false) {
                throw new SlugPersistenceInvariantException('Unable to prepare the Registry Binding insert.');
            }
            $statement->execute([
                'scope_id' => $scope->id,
                'entity_type' => $entity->entityType,
                'entity_key' => $entity->entityKey,
                'status' => BindingStatusEnum::RELEASED->value,
                'revision' => 0,
                'history_sequence' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $created = true;
        } catch (PDOException $exception) {
            if (! PdoDuplicateClassifier::matches($exception, 'uk_binding_identity')) {
                throw $exception;
            }
        }

        $row = $this->findBindingRow($scope->id, $entity, true);
        if ($row === null) {
            throw new SlugPersistenceInvariantException('Binding disappeared during Registry bootstrap.');
        }

        return $this->bindingRecordFromRow($row, $created);
    }

    /**
     * Read and lock an existing Binding row without hydrating Registry claims.
     *
     * Lifecycle transfer uses this primitive to complete every Binding lock
     * before it starts the separate Registry claim-lock phase.
     */
    public function findBindingRecord(ScopeDTO $scope, EntityReference $entity, bool $forUpdate = false): ?RegistryBindingRecord
    {
        $row = $this->findBindingRow($scope->id, $entity, $forUpdate);
        if ($row === null) {
            return null;
        }
        return $this->bindingRecordFromRow($row, false);
    }

    public function findClaimByScopeSlug(int $scopeId, Slug $slug, bool $forUpdate = false): ?RegistryClaimRecord
    {
        return $this->findClaim('r.scope_id = :scope_id AND r.slug = :slug', [
            'scope_id' => $scopeId,
            'slug' => $slug->value,
        ], $forUpdate);
    }

    public function findClaimByBindingSlug(int $bindingId, Slug $slug, bool $forUpdate = false): ?RegistryClaimRecord
    {
        return $this->findClaim('r.binding_id = :binding_id AND r.slug = :slug', [
            'binding_id' => $bindingId,
            'slug' => $slug->value,
        ], $forUpdate);
    }

    /** @return list<RegistryClaimRecord> */
    public function findClaimsForBinding(int $bindingId, bool $forUpdate = false): array
    {
        if ($bindingId < 0) {
            throw new SlugPersistenceInvariantException('Binding id cannot be negative.');
        }
        $suffix = $forUpdate ? ' FOR UPDATE' : '';
        $statement = $this->pdo->prepare(
            'SELECT r.id, r.scope_id, r.binding_id, r.slug, r.claim_role, r.claimed_at, r.updated_at, '
            . 's.namespace, s.locale_key, s.context_key, s.profile_key, b.entity_type, b.entity_key '
            . 'FROM maa_slug_registry r '
            . 'INNER JOIN maa_slug_scopes s ON s.id = r.scope_id '
            . 'INNER JOIN maa_slug_bindings b ON b.id = r.binding_id '
            . 'WHERE r.binding_id = :binding_id ORDER BY r.scope_id ASC, r.slug ASC, r.id ASC' . $suffix,
        );
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare the Registry claim list lookup.');
        }
        $statement->execute(['binding_id' => $bindingId]);
        $rows = PdoRowHydrator::many($statement->fetchAll(PDO::FETCH_ASSOC));

        return array_map(fn(array $row): RegistryClaimRecord => $this->claimFromRow($row), $rows);
    }

    /**
     * @param list<int> $bindingIds
     * @return list<RegistryClaimRecord>
     */
    public function findClaimsForBindings(array $bindingIds, bool $forUpdate = false): array
    {
        if ($bindingIds === []) {
            return [];
        }
        $placeholders = [];
        $parameters = [];
        foreach ($bindingIds as $index => $bindingId) {
            if ($bindingId < 0) {
                throw new SlugPersistenceInvariantException('Binding id cannot be negative.');
            }
            $parameter = 'binding_id_' . $index;
            $placeholder = ':' . $parameter;
            $placeholders[] = $placeholder;
            $parameters[$parameter] = $bindingId;
        }
        if (count(array_unique($bindingIds)) !== count($bindingIds)) {
            throw new SlugPersistenceInvariantException('Binding ids must be distinct for a multi-binding claim lock.');
        }

        $suffix = $forUpdate ? ' FOR UPDATE' : '';
        $statement = $this->pdo->prepare(
            'SELECT r.id, r.scope_id, r.binding_id, r.slug, r.claim_role, r.claimed_at, r.updated_at, '
            . 's.namespace, s.locale_key, s.context_key, s.profile_key, b.entity_type, b.entity_key '
            . 'FROM maa_slug_registry r '
            . 'INNER JOIN maa_slug_scopes s ON s.id = r.scope_id '
            . 'INNER JOIN maa_slug_bindings b ON b.id = r.binding_id '
            . 'WHERE r.binding_id IN (' . implode(', ', $placeholders) . ') '
            . 'ORDER BY r.scope_id ASC, r.slug ASC, r.id ASC' . $suffix,
        );
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare the multi-binding Registry claim list lookup.');
        }
        $statement->execute($parameters);
        $rows = PdoRowHydrator::many($statement->fetchAll(PDO::FETCH_ASSOC));

        return array_map(fn(array $row): RegistryClaimRecord => $this->claimFromRow($row), $rows);
    }

    /**
     * Lock the existing Registry rows for an exact, deterministic set of
     * (scope_id, slug) keys. Missing candidates are intentionally not gap
     * locked; the unique Registry key remains the race authority at insert.
     *
     * @param list<array{scope_id: int, slug: string}> $keys
     * @return list<RegistryClaimRecord>
     */
    public function findClaimsForKeys(array $keys, bool $forUpdate = false): array
    {
        if ($keys === []) {
            return [];
        }

        $normalized = [];
        foreach ($keys as $key) {
            if ($key['scope_id'] < 0 || $key['slug'] === '') {
                throw new SlugPersistenceInvariantException('Registry lock key is invalid.');
            }
            $normalized[$key['scope_id'] . "\0" . $key['slug']] = $key;
        }
        $normalized = array_values($normalized);
        usort($normalized, static fn(array $left, array $right): int => $left['scope_id'] <=> $right['scope_id'] ?: strcmp($left['slug'], $right['slug']));

        $predicates = [];
        $parameters = [];
        foreach ($normalized as $index => $key) {
            $scopeParameter = 'lock_scope_' . $index;
            $slugParameter = 'lock_slug_' . $index;
            $predicates[] = '(r.scope_id = :' . $scopeParameter . ' AND r.slug = :' . $slugParameter . ')';
            $parameters[$scopeParameter] = $key['scope_id'];
            $parameters[$slugParameter] = $key['slug'];
        }

        $suffix = $forUpdate ? ' FOR UPDATE' : '';
        $statement = $this->pdo->prepare(
            'SELECT r.id, r.scope_id, r.binding_id, r.slug, r.claim_role, r.claimed_at, r.updated_at '
            . 'FROM maa_slug_registry r WHERE ' . implode(' OR ', $predicates)
            . ' ORDER BY r.scope_id ASC, r.slug ASC, r.id ASC' . $suffix,
        );
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare the exact Registry lock plan lookup.');
        }
        $statement->execute($parameters);
        $rows = PdoRowHydrator::many($statement->fetchAll(PDO::FETCH_ASSOC));
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn(array $row): int => PdoRowHydrator::nonNegativeInt($row, 'id'), $rows);
        $placeholders = [];
        $hydrationParameters = [];
        foreach ($ids as $index => $id) {
            $parameter = 'registry_id_' . $index;
            $placeholders[] = ':' . $parameter;
            $hydrationParameters[$parameter] = $id;
        }
        $hydration = $this->pdo->prepare(
            'SELECT r.id, r.scope_id, r.binding_id, r.slug, r.claim_role, r.claimed_at, r.updated_at, '
            . 's.namespace, s.locale_key, s.context_key, s.profile_key, b.entity_type, b.entity_key '
            . 'FROM maa_slug_registry r '
            . 'INNER JOIN maa_slug_scopes s ON s.id = r.scope_id '
            . 'INNER JOIN maa_slug_bindings b ON b.id = r.binding_id '
            . 'WHERE r.id IN (' . implode(', ', $placeholders) . ') '
            . 'ORDER BY r.scope_id ASC, r.slug ASC, r.id ASC',
        );
        if ($hydration === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare the exact Registry lock plan hydration.');
        }
        $hydration->execute($hydrationParameters);
        $hydratedRows = PdoRowHydrator::many($hydration->fetchAll(PDO::FETCH_ASSOC));

        return array_map(fn(array $row): RegistryClaimRecord => $this->claimFromRow($row), $hydratedRows);
    }

    /**
     * Read one deterministic Registry key range and, when requested, retain
     * its Registry row locks for the remainder of the enclosing transaction.
     *
     * The range is intentionally scoped to one Scope. Callers must include
     * every existing participant key and every candidate key before mutation;
     * the binary Scope/slug index then acquires the existing range in
     * (scope_id, slug, id) order. Correctness for an absent candidate does not
     * depend on a gap lock; the unique Registry key handles a later insert race.
     *
     * @return list<RegistryClaimRecord>
     */
    public function findClaimsInScopeSlugRange(
        int $scopeId,
        string $minimumSlug,
        string $maximumSlug,
        bool $forUpdate = false,
    ): array {
        if ($scopeId < 0) {
            throw new SlugPersistenceInvariantException('Scope id cannot be negative.');
        }
        if ($minimumSlug === '' || $maximumSlug === '') {
            throw new SlugPersistenceInvariantException('Registry slug range bounds must be non-empty.');
        }
        if (strcmp($minimumSlug, $maximumSlug) > 0) {
            throw new SlugPersistenceInvariantException('Registry slug range bounds must be ordered.');
        }

        $suffix = $forUpdate ? ' FOR UPDATE' : '';
        $statement = $this->pdo->prepare(
            'SELECT r.id, r.scope_id, r.binding_id, r.slug, r.claim_role, r.claimed_at, r.updated_at '
            . 'FROM maa_slug_registry r '
            . 'WHERE r.scope_id = :scope_id AND r.slug >= :minimum_slug AND r.slug <= :maximum_slug '
            . 'ORDER BY r.scope_id ASC, r.slug ASC, r.id ASC' . $suffix,
        );
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare the Registry slug range lookup.');
        }
        $statement->execute([
            'scope_id' => $scopeId,
            'minimum_slug' => $minimumSlug,
            'maximum_slug' => $maximumSlug,
        ]);
        $rows = PdoRowHydrator::many($statement->fetchAll(PDO::FETCH_ASSOC));
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn(array $row): int => PdoRowHydrator::nonNegativeInt($row, 'id'), $rows);
        $placeholders = [];
        $parameters = [];
        foreach ($ids as $index => $id) {
            $parameter = 'registry_id_' . $index;
            $placeholders[] = ':' . $parameter;
            $parameters[$parameter] = $id;
        }
        $hydration = $this->pdo->prepare(
            'SELECT r.id, r.scope_id, r.binding_id, r.slug, r.claim_role, r.claimed_at, r.updated_at, '
            . 's.namespace, s.locale_key, s.context_key, s.profile_key, b.entity_type, b.entity_key '
            . 'FROM maa_slug_registry r '
            . 'INNER JOIN maa_slug_scopes s ON s.id = r.scope_id '
            . 'INNER JOIN maa_slug_bindings b ON b.id = r.binding_id '
            . 'WHERE r.id IN (' . implode(', ', $placeholders) . ') '
            . 'ORDER BY r.scope_id ASC, r.slug ASC, r.id ASC',
        );
        if ($hydration === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare the Registry claim hydration.');
        }
        $hydration->execute($parameters);
        $hydratedRows = PdoRowHydrator::many($hydration->fetchAll(PDO::FETCH_ASSOC));

        return array_map(fn(array $row): RegistryClaimRecord => $this->claimFromRow($row), $hydratedRows);
    }

    public function insertClaim(int $scopeId, int $bindingId, Slug $slug, RegistryRoleEnum $role): RegistryClaimRecord
    {
        $now = $this->timestamp();
        $statement = $this->pdo->prepare(
            'INSERT INTO maa_slug_registry '
            . '(scope_id, binding_id, slug, claim_role, claimed_at, updated_at) '
            . 'VALUES (:scope_id, :binding_id, :slug, :claim_role, :claimed_at, :updated_at)',
        );
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare the Registry claim insert.');
        }
        $statement->execute([
            'scope_id' => $scopeId,
            'binding_id' => $bindingId,
            'slug' => $slug->value,
            'claim_role' => $role->value,
            'claimed_at' => $now,
            'updated_at' => $now,
        ]);

        $id = filter_var($this->pdo->lastInsertId(), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new SlugPersistenceInvariantException('Registry claim insert did not return a valid id.');
        }
        $claim = $this->findClaimById($id, false);
        if ($claim === null) {
            throw new SlugPersistenceInvariantException('Inserted Registry claim cannot be read back.');
        }

        return $claim;
    }

    public function activateCurrent(int $bindingId, int $expectedRevision, int $registryId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE maa_slug_bindings '
            . 'SET current_registry_id = :current_registry_id, status = :status, revision = revision + 1, updated_at = :updated_at '
            . 'WHERE id = :binding_id AND revision = :expected_revision',
        );
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare the current Binding update.');
        }
        $statement->execute([
            'current_registry_id' => $registryId,
            'status' => BindingStatusEnum::ACTIVE->value,
            'updated_at' => $this->timestamp(),
            'binding_id' => $bindingId,
            'expected_revision' => $expectedRevision,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new SlugRevisionConflictException('Binding revision changed before current ownership activation.');
        }
    }

    public function mutateBinding(
        int $bindingId,
        int $expectedRevision,
        BindingStatusEnum $status,
        ?int $currentRegistryId,
        int $historySequence,
    ): void {
        if ($bindingId < 0 || $expectedRevision < 0 || $historySequence < 0) {
            throw new SlugPersistenceInvariantException('Binding mutation identity is invalid.');
        }
        $statement = $this->pdo->prepare(
            'UPDATE maa_slug_bindings SET current_registry_id = :current_registry_id, status = :status, '
            . 'revision = revision + 1, history_sequence = :history_sequence, updated_at = :updated_at '
            . 'WHERE id = :binding_id AND revision = :expected_revision',
        );
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare the Binding lifecycle update.');
        }
        $statement->execute([
            'current_registry_id' => $currentRegistryId,
            'status' => $status->value,
            'history_sequence' => $historySequence,
            'updated_at' => $this->timestamp(),
            'binding_id' => $bindingId,
            'expected_revision' => $expectedRevision,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new SlugRevisionConflictException('Binding revision changed before lifecycle mutation.');
        }
    }

    public function updateClaimRole(int $registryId, RegistryRoleEnum $role): RegistryClaimRecord
    {
        if ($registryId < 0) {
            throw new SlugPersistenceInvariantException('Registry id cannot be negative.');
        }
        $statement = $this->pdo->prepare(
            'UPDATE maa_slug_registry SET claim_role = :claim_role, updated_at = :updated_at WHERE id = :registry_id',
        );
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare the Registry role update.');
        }
        $statement->execute([
            'claim_role' => $role->value,
            'updated_at' => $this->timestamp(),
            'registry_id' => $registryId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new SlugPersistenceInvariantException('Registry role update did not affect exactly one claim.');
        }
        $claim = $this->findClaimById($registryId, false);
        if ($claim === null) {
            throw new SlugPersistenceInvariantException('Updated Registry claim cannot be read back.');
        }

        return $claim;
    }

    public function deleteClaim(int $registryId): void
    {
        if ($registryId < 0) {
            throw new SlugPersistenceInvariantException('Registry id cannot be negative.');
        }
        $statement = $this->pdo->prepare('DELETE FROM maa_slug_registry WHERE id = :registry_id');
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare the Registry claim delete.');
        }
        $statement->execute(['registry_id' => $registryId]);
        if ($statement->rowCount() !== 1) {
            throw new SlugPersistenceInvariantException('Registry claim delete did not affect exactly one claim.');
        }
    }

    public function binding(BindingIdentityDTO $identity, bool $forUpdate = false): ?BindingDTO
    {
        return $this->scopes->findBinding($identity->scopeProfile, $identity->entity, $forUpdate);
    }

    private function findClaimById(int $id, bool $forUpdate): ?RegistryClaimRecord
    {
        return $this->findClaim('r.id = :registry_id', ['registry_id' => $id], $forUpdate);
    }

    /** @param array<string, int|string> $parameters */
    private function findClaim(string $predicate, array $parameters, bool $forUpdate): ?RegistryClaimRecord
    {
        $suffix = $forUpdate ? ' FOR UPDATE' : '';
        $statement = $this->pdo->prepare(
            'SELECT r.id, r.scope_id, r.binding_id, r.slug, r.claim_role, r.claimed_at, r.updated_at, '
            . 's.namespace, s.locale_key, s.context_key, s.profile_key, b.entity_type, b.entity_key '
            . 'FROM maa_slug_registry r '
            . 'INNER JOIN maa_slug_scopes s ON s.id = r.scope_id '
            . 'INNER JOIN maa_slug_bindings b ON b.id = r.binding_id '
            . 'WHERE ' . $predicate . $suffix,
        );
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare the Registry claim lookup.');
        }
        $statement->execute($parameters);
        $row = PdoRowHydrator::one($statement->fetch(PDO::FETCH_ASSOC));

        return $row === null ? null : $this->claimFromRow($row);
    }

    /** @return array<string, mixed>|null */
    private function findScopeRow(SlugScope $scope, bool $forUpdate): ?array
    {
        $suffix = $forUpdate ? ' FOR UPDATE' : '';
        $statement = $this->pdo->prepare(
            'SELECT id, namespace, locale_key, context_key, profile_key, created_at, updated_at '
            . 'FROM maa_slug_scopes '
            . 'WHERE namespace = :namespace AND locale_key = :locale_key AND context_key = :context_key' . $suffix,
        );
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare the Registry Scope lookup.');
        }
        $statement->execute([
            'namespace' => $scope->namespace,
            'locale_key' => $scope->localeKey ?? '',
            'context_key' => $scope->contextKey ?? '',
        ]);

        return PdoRowHydrator::one($statement->fetch(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    private function findBindingRow(int $scopeId, EntityReference $entity, bool $forUpdate): ?array
    {
        $suffix = $forUpdate ? ' FOR UPDATE' : '';
        $statement = $this->pdo->prepare(
            'SELECT id, scope_id, current_registry_id, status, revision '
            . 'FROM maa_slug_bindings '
            . 'WHERE scope_id = :scope_id AND entity_type = :entity_type AND entity_key = :entity_key' . $suffix,
        );
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare the Registry Binding lookup.');
        }
        $statement->execute([
            'scope_id' => $scopeId,
            'entity_type' => $entity->entityType,
            'entity_key' => $entity->entityKey,
        ]);

        return PdoRowHydrator::one($statement->fetch(PDO::FETCH_ASSOC));
    }

    private function validatedBindingRecord(ScopeDTO $scope, EntityReference $entity, bool $created): RegistryBindingRecord
    {
        $binding = $this->scopes->findBinding(
            new ScopeProfileRequestDTO($scope->scope, $scope->profileKey),
            $entity,
            true,
        );
        if ($binding === null) {
            throw new SlugPersistenceInvariantException('Binding disappeared during invariant validation.');
        }

        return new RegistryBindingRecord(
            $binding->id,
            $scope->id,
            $binding->state->status,
            $binding->currentClaim?->id,
            $binding->state->revision,
            $created,
        );
    }

    /** @param array<string, mixed> $row */
    private function bindingRecordFromRow(array $row, bool $created): RegistryBindingRecord
    {
        $status = BindingStatusEnum::tryFrom(PdoRowHydrator::string($row, 'status'));
        if ($status === null) {
            throw new SlugPersistenceInvariantException('Binding contains an unknown status.');
        }

        return new RegistryBindingRecord(
            PdoRowHydrator::nonNegativeInt($row, 'id'),
            PdoRowHydrator::nonNegativeInt($row, 'scope_id'),
            $status,
            $this->nullableNonNegativeInt($row, 'current_registry_id'),
            PdoRowHydrator::nonNegativeInt($row, 'revision'),
            $created,
        );
    }

    /** @param array<string, mixed> $row */
    private function nullableNonNegativeInt(array $row, string $key): ?int
    {
        if (! array_key_exists($key, $row) || $row[$key] === null) {
            return null;
        }

        return PdoRowHydrator::nonNegativeInt($row, $key);
    }

    /** @param array<string, mixed> $row */
    private function claimFromRow(array $row): RegistryClaimRecord
    {
        $role = RegistryRoleEnum::tryFrom(PdoRowHydrator::string($row, 'claim_role'));
        if ($role === null) {
            throw new SlugPersistenceInvariantException('Registry contains an unknown claim role.');
        }

        return new RegistryClaimRecord(
            PdoRowHydrator::nonNegativeInt($row, 'id'),
            PdoRowHydrator::nonNegativeInt($row, 'scope_id'),
            PdoRowHydrator::nonNegativeInt($row, 'binding_id'),
            PdoRowHydrator::string($row, 'namespace'),
            PdoRowHydrator::string($row, 'locale_key') === '' ? null : PdoRowHydrator::string($row, 'locale_key'),
            PdoRowHydrator::string($row, 'context_key') === '' ? null : PdoRowHydrator::string($row, 'context_key'),
            PdoRowHydrator::string($row, 'profile_key'),
            PdoRowHydrator::string($row, 'entity_type'),
            PdoRowHydrator::string($row, 'entity_key'),
            PdoRowHydrator::string($row, 'slug'),
            $role,
            $this->date(PdoRowHydrator::string($row, 'claimed_at'), 'registry.claimed_at'),
            $this->date(PdoRowHydrator::string($row, 'updated_at'), 'registry.updated_at'),
        );
    }

    /** @param array<string, mixed> $row */
    private function scopeFromRow(array $row): ScopeDTO
    {
        return new ScopeDTO(
            PdoRowHydrator::nonNegativeInt($row, 'id'),
            new SlugScope(
                PdoRowHydrator::string($row, 'namespace'),
                PdoRowHydrator::string($row, 'locale_key') === '' ? null : PdoRowHydrator::string($row, 'locale_key'),
                PdoRowHydrator::string($row, 'context_key') === '' ? null : PdoRowHydrator::string($row, 'context_key'),
            ),
            new SlugProfileKey(PdoRowHydrator::string($row, 'profile_key')),
            $this->date(PdoRowHydrator::string($row, 'created_at'), 'scope.created_at'),
            $this->date(PdoRowHydrator::string($row, 'updated_at'), 'scope.updated_at'),
        );
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function date(string $value, string $field): DateTimeImmutable
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}\z/', $value) !== 1) {
            throw new SlugPersistenceInvariantException(sprintf('%s must contain six microseconds.', $field));
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new SlugPersistenceInvariantException(sprintf('%s is not a valid UTC timestamp.', $field));
        }

        return $date;
    }
}
