<?php

declare(strict_types=1);

namespace Maatify\Slug\Infrastructure\Persistence\PDO\Registry;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Slug\DTO\BindingDTO;
use Maatify\Slug\DTO\BindingIdentityDTO;
use Maatify\Slug\DTO\ScopeDTO;
use Maatify\Slug\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Enum\BindingStatusEnum;
use Maatify\Slug\Enum\RegistryRoleEnum;
use Maatify\Slug\Exception\SlugPersistenceInvariantException;
use Maatify\Slug\Exception\SlugRevisionConflictException;
use Maatify\Slug\Exception\SlugScopeProfileMismatchException;
use Maatify\Slug\Identity\EntityReference;
use Maatify\Slug\Identity\Slug;
use Maatify\Slug\Identity\SlugProfileKey;
use Maatify\Slug\Infrastructure\Persistence\PDO\Connection\PdoCapabilityGuard;
use Maatify\Slug\Infrastructure\Persistence\PDO\Connection\PdoDuplicateClassifier;
use Maatify\Slug\Infrastructure\Persistence\PDO\Connection\PdoRowHydrator;
use Maatify\Slug\Persistence\Contract\ScopePersistenceInterface;
use Maatify\Slug\Profile\Contracts\SlugProfileRegistryInterface;
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
        $row = $this->findBindingRow($scope->id, $entity, true);
        if ($row !== null) {
            return $this->bindingFromRow($row, false);
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

        return $this->bindingFromRow($row, $created);
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
        $claim = $this->findClaimById($id, true);
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

    /** @param array<string, mixed> $row */
    private function bindingFromRow(array $row, bool $created): RegistryBindingRecord
    {
        $status = BindingStatusEnum::tryFrom(PdoRowHydrator::string($row, 'status'));
        if ($status === null) {
            throw new SlugPersistenceInvariantException('Binding contains an unknown status.');
        }

        return new RegistryBindingRecord(
            PdoRowHydrator::nonNegativeInt($row, 'id'),
            PdoRowHydrator::nonNegativeInt($row, 'scope_id'),
            $status,
            $row['current_registry_id'] === null ? null : PdoRowHydrator::nonNegativeInt($row, 'current_registry_id'),
            PdoRowHydrator::nonNegativeInt($row, 'revision'),
            $created,
        );
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
