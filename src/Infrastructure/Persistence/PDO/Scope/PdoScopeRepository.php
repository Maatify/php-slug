<?php

declare(strict_types=1);

namespace Maatify\Slug\Infrastructure\Persistence\PDO\Scope;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Slug\Contract\SlugScopeRegistryInterface;
use Maatify\Slug\DTO\BindingDTO;
use Maatify\Slug\DTO\BindingIdentityDTO;
use Maatify\Slug\DTO\BindingStateDTO;
use Maatify\Slug\DTO\ScopeDTO;
use Maatify\Slug\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\DTO\RegistryClaimDTO;
use Maatify\Slug\Enum\BindingStatusEnum;
use Maatify\Slug\Enum\RegistryRoleEnum;
use Maatify\Slug\Exception\SlugPersistenceInvariantException;
use Maatify\Slug\Exception\SlugScopeProfileMismatchException;
use Maatify\Slug\Identity\EntityReference;
use Maatify\Slug\Identity\Slug;
use Maatify\Slug\Identity\SlugProfileKey;
use Maatify\Slug\Infrastructure\Persistence\PDO\Connection\PdoCapabilityGuard;
use Maatify\Slug\Infrastructure\Persistence\PDO\Connection\PdoDuplicateClassifier;
use Maatify\Slug\Infrastructure\Persistence\PDO\Connection\PdoRowHydrator;
use Maatify\Slug\Internal\Transaction\PdoTransactionCoordinator;
use Maatify\Slug\Persistence\Contract\ScopePersistenceInterface;
use Maatify\Slug\Profile\Contracts\SlugProfileRegistryInterface;
use Maatify\Slug\Scope\Value\SlugScope;
use Throwable;

final readonly class PdoScopeRepository implements SlugScopeRegistryInterface, ScopePersistenceInterface
{
    private PdoCapabilityGuard $capabilities;

    private PdoTransactionCoordinator $transactions;

    public function __construct(
        private PDO $pdo,
        private SlugProfileRegistryInterface $profiles,
        private ClockInterface $clock,
        ?PdoCapabilityGuard $capabilities = null,
        ?PdoTransactionCoordinator $transactions = null,
    ) {
        $this->capabilities = $capabilities ?? new PdoCapabilityGuard($pdo);
        $this->transactions = $transactions ?? new PdoTransactionCoordinator($pdo);
    }

    public function ensureScope(ScopeProfileRequestDTO $request): ScopeDTO
    {
        $this->profiles->get($request->expectedProfileKey);
        $this->capabilities->assertInstalledSchemaSupported();

        return $this->transactions->run(fn(): ScopeDTO => $this->ensureScopeInsideTransaction($request));
    }

    public function ensureBindingPlaceholder(ScopeProfileRequestDTO $request, EntityReference $entity): BindingDTO
    {
        $this->profiles->get($request->expectedProfileKey);
        $this->capabilities->assertInstalledSchemaSupported();

        return $this->transactions->run(function () use ($request, $entity): BindingDTO {
            $scope = $this->ensureScopeInsideTransaction($request);
            $row = $this->findBindingRow($scope->id, $entity, true);

            if ($row === null) {
                $now = $this->timestamp();
                try {
                    $statement = $this->pdo->prepare(
                        'INSERT INTO maa_slug_bindings '
                        . '(scope_id, entity_type, entity_key, current_registry_id, status, revision, history_sequence, created_at, updated_at) '
                        . 'VALUES (:scope_id, :entity_type, :entity_key, NULL, :status, :revision, :history_sequence, :created_at, :updated_at)',
                    );
                    if ($statement === false) {
                        throw new SlugPersistenceInvariantException('Unable to prepare the Binding placeholder insert.');
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
                } catch (PDOException $exception) {
                    if (! PdoDuplicateClassifier::matches($exception, 'uk_binding_identity')) {
                        throw $exception;
                    }
                }

                $row = $this->findBindingRow($scope->id, $entity, true);
            }

            if ($row === null) {
                throw new SlugPersistenceInvariantException('Binding placeholder disappeared during bootstrap.');
            }

            return $this->hydrateBinding($row, $request->expectedProfileKey, true);
        });
    }

    public function findBinding(ScopeProfileRequestDTO $request, EntityReference $entity, bool $forUpdate = false): ?BindingDTO
    {
        $this->profiles->get($request->expectedProfileKey);
        $scope = $this->findScopeRow($request, $forUpdate);
        if ($scope === null) {
            return null;
        }

        $row = $this->findBindingRow(PdoRowHydrator::nonNegativeInt($scope, 'id'), $entity, $forUpdate);
        return $row === null ? null : $this->hydrateBinding($row, $request->expectedProfileKey, $forUpdate);
    }

    public function findBindingById(int $bindingId, bool $forUpdate = false): ?BindingDTO
    {
        if ($bindingId < 0) {
            throw new SlugPersistenceInvariantException('Binding id cannot be negative.');
        }

        $suffix = $forUpdate ? ' FOR UPDATE' : '';
        $statement = $this->pdo->prepare(
            'SELECT b.id, b.scope_id, b.entity_type, b.entity_key, b.current_registry_id, b.status, '
            . 'b.revision, b.history_sequence, b.created_at, b.updated_at, '
            . 's.namespace, s.locale_key, s.context_key, s.profile_key '
            . 'FROM maa_slug_bindings b INNER JOIN maa_slug_scopes s ON s.id = b.scope_id '
            . 'WHERE b.id = :binding_id' . $suffix,
        );
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare the Binding lookup.');
        }
        $statement->execute(['binding_id' => $bindingId]);
        $row = PdoRowHydrator::one($statement->fetch(PDO::FETCH_ASSOC));

        return $row === null ? null : $this->hydrateBinding($row, null, $forUpdate);
    }

    /** @return array<string, mixed> */
    private function ensureScopeRow(ScopeProfileRequestDTO $request): array
    {
        $scope = $request->scope;
        $namespace = $scope->namespace;
        $localeKey = $scope->localeKey ?? '';
        $contextKey = $scope->contextKey ?? '';
        $row = $this->findScopeRowByValues($namespace, $localeKey, $contextKey, true);

        if ($row === null) {
            $now = $this->timestamp();
            try {
                $statement = $this->pdo->prepare(
                    'INSERT INTO maa_slug_scopes '
                    . '(namespace, locale_key, context_key, profile_key, created_at, updated_at) '
                    . 'VALUES (:namespace, :locale_key, :context_key, :profile_key, :created_at, :updated_at)',
                );
                if ($statement === false) {
                    throw new SlugPersistenceInvariantException('Unable to prepare the Scope insert.');
                }
                $statement->execute([
                    'namespace' => $namespace,
                    'locale_key' => $localeKey,
                    'context_key' => $contextKey,
                    'profile_key' => $request->expectedProfileKey->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (PDOException $exception) {
                if (! PdoDuplicateClassifier::matches($exception, 'uk_scope_identity')) {
                    throw $exception;
                }
            }

            $row = $this->findScopeRowByValues($namespace, $localeKey, $contextKey, true);
        }

        if ($row === null) {
            throw new SlugPersistenceInvariantException('Scope disappeared during bootstrap.');
        }

        if (PdoRowHydrator::string($row, 'profile_key') !== $request->expectedProfileKey->value) {
            throw new SlugScopeProfileMismatchException('The existing Scope uses a different slug profile.');
        }

        return $row;
    }

    private function ensureScopeInsideTransaction(ScopeProfileRequestDTO $request): ScopeDTO
    {
        return $this->scopeFromRow($this->ensureScopeRow($request));
    }

    /** @return array<string, mixed>|null */
    private function findScopeRow(ScopeProfileRequestDTO $request, bool $forUpdate): ?array
    {
        $row = $this->findScopeRowByValues(
            $request->scope->namespace,
            $request->scope->localeKey ?? '',
            $request->scope->contextKey ?? '',
            $forUpdate,
        );
        if ($row !== null && PdoRowHydrator::string($row, 'profile_key') !== $request->expectedProfileKey->value) {
            throw new SlugScopeProfileMismatchException('The existing Scope uses a different slug profile.');
        }

        return $row;
    }

    /** @return array<string, mixed>|null */
    private function findScopeRowByValues(string $namespace, string $localeKey, string $contextKey, bool $forUpdate): ?array
    {
        $suffix = $forUpdate ? ' FOR UPDATE' : '';
        $statement = $this->pdo->prepare(
            'SELECT id, namespace, locale_key, context_key, profile_key, created_at, updated_at '
            . 'FROM maa_slug_scopes '
            . 'WHERE namespace = :namespace AND locale_key = :locale_key AND context_key = :context_key' . $suffix,
        );
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare the Scope lookup.');
        }
        $statement->execute([
            'namespace' => $namespace,
            'locale_key' => $localeKey,
            'context_key' => $contextKey,
        ]);
        return PdoRowHydrator::one($statement->fetch(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    private function findBindingRow(int $scopeId, EntityReference $entity, bool $forUpdate): ?array
    {
        $suffix = $forUpdate ? ' FOR UPDATE' : '';
        $statement = $this->pdo->prepare(
            'SELECT b.id, b.scope_id, b.entity_type, b.entity_key, b.current_registry_id, b.status, '
            . 'b.revision, b.history_sequence, b.created_at, b.updated_at, '
            . 's.namespace, s.locale_key, s.context_key, s.profile_key '
            . 'FROM maa_slug_bindings b INNER JOIN maa_slug_scopes s ON s.id = b.scope_id '
            . 'WHERE b.scope_id = :scope_id AND b.entity_type = :entity_type AND b.entity_key = :entity_key' . $suffix,
        );
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare the Binding lookup.');
        }
        $statement->execute([
            'scope_id' => $scopeId,
            'entity_type' => $entity->entityType,
            'entity_key' => $entity->entityKey,
        ]);
        return PdoRowHydrator::one($statement->fetch(PDO::FETCH_ASSOC));
    }

    /** @param array<string, mixed> $row */
    private function hydrateBinding(array $row, ?SlugProfileKey $expectedProfileKey, bool $forUpdate): BindingDTO
    {
        try {
            $profileKey = new SlugProfileKey(PdoRowHydrator::string($row, 'profile_key'));
            if ($expectedProfileKey !== null && $profileKey->value !== $expectedProfileKey->value) {
                throw new SlugScopeProfileMismatchException('The existing Scope uses a different slug profile.');
            }
            $profile = $this->profiles->get($profileKey);
            $scope = new SlugScope(
                PdoRowHydrator::string($row, 'namespace'),
                PdoRowHydrator::string($row, 'locale_key') === '' ? null : PdoRowHydrator::string($row, 'locale_key'),
                PdoRowHydrator::string($row, 'context_key') === '' ? null : PdoRowHydrator::string($row, 'context_key'),
            );
            $entity = new EntityReference(PdoRowHydrator::string($row, 'entity_type'), PdoRowHydrator::string($row, 'entity_key'));
            $identity = new BindingIdentityDTO(new ScopeProfileRequestDTO($scope, $profileKey), $entity);
            $status = BindingStatusEnum::tryFrom(PdoRowHydrator::string($row, 'status'));
            if ($status === null) {
                throw new SlugPersistenceInvariantException('Binding contains an unknown status.');
            }
            $revision = $this->nonNegativeInt($row['revision'], 'binding.revision');
            $historySequence = $this->nonNegativeInt($row['history_sequence'], 'binding.history_sequence');
            $currentRegistryId = $row['current_registry_id'] === null ? null : $this->nonNegativeInt($row['current_registry_id'], 'binding.current_registry_id');
            $claims = $this->registryClaims(
                $this->nonNegativeInt($row['id'], 'binding.id'),
                $this->nonNegativeInt($row['scope_id'], 'binding.scope_id'),
                PdoRowHydrator::string($row, 'entity_type'),
                PdoRowHydrator::string($row, 'entity_key'),
                $forUpdate,
            );

            if ($status === BindingStatusEnum::RELEASED) {
                if ($currentRegistryId !== null || $claims !== []) {
                    throw new SlugPersistenceInvariantException('Released Binding has live ownership or a current pointer.');
                }
                $currentClaim = null;
                $currentSlug = null;
            } else {
                $currentClaims = array_values(array_filter(
                    $claims,
                    static fn(RegistryClaimDTO $claim): bool => $claim->role === RegistryRoleEnum::CURRENT_CANONICAL,
                ));
                if ($currentRegistryId === null || count($currentClaims) !== 1) {
                    throw new SlugPersistenceInvariantException('Active or inactive Binding has an invalid current pointer.');
                }
                $currentClaim = $currentClaims[0];
                if ($currentClaim->id !== $currentRegistryId || $currentClaim->role !== RegistryRoleEnum::CURRENT_CANONICAL) {
                    throw new SlugPersistenceInvariantException('Binding current pointer does not reference its current claim.');
                }
                $currentSlug = $currentClaim->slug;
            }

            return new BindingDTO(
                $this->nonNegativeInt($row['id'], 'binding.id'),
                $identity,
                new BindingStateDTO($status, $currentSlug, $revision, $historySequence),
                $currentClaim,
                $this->date(PdoRowHydrator::string($row, 'created_at'), 'binding.created_at'),
                $this->date(PdoRowHydrator::string($row, 'updated_at'), 'binding.updated_at'),
            );
        } catch (SlugScopeProfileMismatchException $exception) {
            throw $exception;
        } catch (SlugPersistenceInvariantException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            throw new SlugPersistenceInvariantException('Binding row violates the persistence invariant.', 0, $throwable);
        }
    }

    /** @return list<RegistryClaimDTO> */
    private function registryClaims(int $bindingId, int $scopeId, string $entityType, string $entityKey, bool $forUpdate): array
    {
        $suffix = $forUpdate ? ' FOR UPDATE' : '';
        $statement = $this->pdo->prepare(
            'SELECT r.id, r.binding_id, r.scope_id, r.slug, r.claim_role, r.claimed_at, r.updated_at, '
            . 's.namespace, s.locale_key, s.context_key, s.profile_key, '
            . 'b.entity_type, b.entity_key '
            . 'FROM maa_slug_registry r '
            . 'INNER JOIN maa_slug_scopes s ON s.id = r.scope_id '
            . 'INNER JOIN maa_slug_bindings b ON b.id = r.binding_id '
            . 'WHERE r.binding_id = :binding_id ORDER BY r.scope_id ASC, r.slug ASC, r.id ASC' . $suffix,
        );
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare the current claim lookup.');
        }
        $statement->execute([
            'binding_id' => $bindingId,
        ]);
        $rows = PdoRowHydrator::many($statement->fetchAll(PDO::FETCH_ASSOC));

        $claims = [];
        foreach ($rows as $row) {
            if ($this->nonNegativeInt($row['binding_id'], 'registry.binding_id') !== $bindingId
                || $this->nonNegativeInt($row['scope_id'], 'registry.scope_id') !== $scopeId
                || PdoRowHydrator::string($row, 'entity_type') !== $entityType
                || PdoRowHydrator::string($row, 'entity_key') !== $entityKey) {
                throw new SlugPersistenceInvariantException('Registry claim does not match its Binding identity.');
            }
            $profileKey = new SlugProfileKey(PdoRowHydrator::string($row, 'profile_key'));
            $profile = $this->profiles->get($profileKey);
            $scope = new SlugScope(
                PdoRowHydrator::string($row, 'namespace'),
                PdoRowHydrator::string($row, 'locale_key') === '' ? null : PdoRowHydrator::string($row, 'locale_key'),
                PdoRowHydrator::string($row, 'context_key') === '' ? null : PdoRowHydrator::string($row, 'context_key'),
            );
            $entity = new EntityReference(PdoRowHydrator::string($row, 'entity_type'), PdoRowHydrator::string($row, 'entity_key'));
            $role = RegistryRoleEnum::tryFrom(PdoRowHydrator::string($row, 'claim_role'));
            if ($role === null) {
                throw new SlugPersistenceInvariantException('Registry contains an unknown claim role.');
            }
            $claims[] = new RegistryClaimDTO(
                $this->nonNegativeInt($row['id'], 'registry.id'),
                new BindingIdentityDTO(new ScopeProfileRequestDTO($scope, $profileKey), $entity),
                Slug::fromProfile($profile, PdoRowHydrator::string($row, 'slug')),
                $role,
                $this->date(PdoRowHydrator::string($row, 'claimed_at'), 'registry.claimed_at'),
                $this->date(PdoRowHydrator::string($row, 'updated_at'), 'registry.updated_at'),
            );
        }

        return $claims;
    }

    /** @param array<string, mixed> $row */
    private function scopeFromRow(array $row): ScopeDTO
    {
        try {
            $profileKey = new SlugProfileKey(PdoRowHydrator::string($row, 'profile_key'));

            return new ScopeDTO(
                $this->nonNegativeInt($row['id'], 'scope.id'),
                new SlugScope(
                    PdoRowHydrator::string($row, 'namespace'),
                    PdoRowHydrator::string($row, 'locale_key') === '' ? null : PdoRowHydrator::string($row, 'locale_key'),
                    PdoRowHydrator::string($row, 'context_key') === '' ? null : PdoRowHydrator::string($row, 'context_key'),
                ),
                $profileKey,
                $this->date(PdoRowHydrator::string($row, 'created_at'), 'scope.created_at'),
                $this->date(PdoRowHydrator::string($row, 'updated_at'), 'scope.updated_at'),
            );
        } catch (Throwable $throwable) {
            throw new SlugPersistenceInvariantException('Scope row violates the persistence invariant.', 0, $throwable);
        }
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function date(mixed $value, string $field): DateTimeImmutable
    {
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}\z/', $value) !== 1) {
            throw new SlugPersistenceInvariantException(sprintf('%s must contain six microseconds.', $field));
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new SlugPersistenceInvariantException(sprintf('%s is not a valid UTC timestamp.', $field));
        }

        return $date;
    }

    private function nonNegativeInt(mixed $value, string $field): int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/\A\d+\z/', $value) === 1) {
            $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($integer === false) {
                throw new SlugPersistenceInvariantException(sprintf('%s exceeds PHP integer range.', $field));
            }
        } else {
            throw new SlugPersistenceInvariantException(sprintf('%s must be a non-negative integer.', $field));
        }
        if ($integer < 0) {
            throw new SlugPersistenceInvariantException(sprintf('%s must be non-negative.', $field));
        }

        return $integer;
    }
}
