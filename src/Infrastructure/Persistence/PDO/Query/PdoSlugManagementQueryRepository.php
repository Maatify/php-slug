<?php

declare(strict_types=1);

namespace Maatify\Slug\Infrastructure\Persistence\PDO\Query;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Persistence\Pdo\Pagination\PageResult;
use Maatify\Persistence\Pdo\Pagination\PaginationConfig;
use Maatify\Persistence\Pdo\Pagination\PdoPaginationQueryDescriptor;
use Maatify\Persistence\Pdo\Pagination\PdoPaginator;
use Maatify\Persistence\Pdo\Pagination\SortDirectionEnum;
use Maatify\Persistence\Pdo\Pagination\SortWhitelist;
use Maatify\Slug\Criteria\BindingSearchCriteria;
use Maatify\Slug\Criteria\HistoryCriteria;
use Maatify\Slug\Criteria\RegistryCriteria;
use Maatify\Slug\Criteria\RegistrySearchCriteria;
use Maatify\Slug\DTO\AliasDTO;
use Maatify\Slug\DTO\BindingDTO;
use Maatify\Slug\DTO\BindingIdentityDTO;
use Maatify\Slug\DTO\BindingStateDTO;
use Maatify\Slug\DTO\HistoryEventDTO;
use Maatify\Slug\DTO\RegistryClaimDTO;
use Maatify\Slug\Enum\BindingStatusEnum;
use Maatify\Slug\Enum\HistoryEventTypeEnum;
use Maatify\Slug\Enum\RegistryRoleEnum;
use Maatify\Slug\Exception\SlugPersistenceInvariantException;
use Maatify\Slug\Identity\EntityReference;
use Maatify\Slug\Identity\Slug;
use Maatify\Slug\Identity\SlugProfileKey;
use Maatify\Slug\Infrastructure\Persistence\PDO\Connection\PdoRowHydrator;
use Maatify\Slug\Profile\Contracts\SlugProfileRegistryInterface;
use Maatify\Slug\Scope\Value\SlugScope;

/** Direct-PDO management query adapter backed by the shared paginator. */
final readonly class PdoSlugManagementQueryRepository
{
    private PdoPaginator $paginator;

    public function __construct(
        private PDO $pdo,
        private SlugProfileRegistryInterface $profiles,
    ) {
        $this->paginator = new PdoPaginator();
    }

    /** @return PageResult<AliasDTO> */
    public function listAliases(int $bindingId, PageRequest $request): PageResult
    {
        $predicate = 'r.binding_id = :binding_id AND r.claim_role IN (\'ACTIVE_ALIAS\', \'RETIRED_ALIAS\')';
        $descriptor = new PdoPaginationQueryDescriptor(
            'SELECT COUNT(*) FROM maa_slug_registry r WHERE ' . $predicate,
            ['binding_id' => $bindingId],
            'SELECT COUNT(*) FROM maa_slug_registry r WHERE ' . $predicate,
            ['binding_id' => $bindingId],
            $this->claimSelectSql() . ' WHERE ' . $predicate,
            ['binding_id' => $bindingId],
        );

        return $this->paginator->paginate($this->pdo, $descriptor, $request, self::aliasConfig(), fn(array $row): AliasDTO => $this->aliasFromRow($row));
    }

    /** @return PageResult<HistoryEventDTO> */
    public function getHistory(int $bindingId, ?HistoryEventTypeEnum $eventType, PageRequest $request): PageResult
    {
        $predicate = 'h.binding_id = :binding_id';
        $params = ['binding_id' => $bindingId];
        if ($eventType !== null) {
            $predicate .= ' AND h.event_type = :event_type';
            $params['event_type'] = $eventType->value;
        }
        $all = 'h.binding_id = :binding_id';
        $descriptor = new PdoPaginationQueryDescriptor(
            'SELECT COUNT(*) FROM maa_slug_history h WHERE ' . $all,
            ['binding_id' => $bindingId],
            'SELECT COUNT(*) FROM maa_slug_history h WHERE ' . $predicate,
            $params,
            $this->historySelectSql() . ' WHERE ' . $predicate,
            $params,
        );

        return $this->paginator->paginate($this->pdo, $descriptor, $request, self::historyConfig(), fn(array $row): HistoryEventDTO => $this->historyFromRow($row));
    }

    /** @return PageResult<RegistryClaimDTO> */
    public function inspectRegistry(int $scopeId, ?int $bindingId, ?RegistryRoleEnum $role, PageRequest $request): PageResult
    {
        [$predicate, $params] = $this->registryPredicate($scopeId, $bindingId, $role);
        $descriptor = new PdoPaginationQueryDescriptor(
            'SELECT COUNT(*) FROM maa_slug_registry r WHERE r.scope_id = :scope_id',
            ['scope_id' => $scopeId],
            'SELECT COUNT(*) FROM maa_slug_registry r WHERE ' . $predicate,
            $params,
            $this->claimSelectSql() . ' WHERE ' . $predicate,
            $params,
        );

        return $this->paginator->paginate($this->pdo, $descriptor, $request, self::registryConfig(), fn(array $row): RegistryClaimDTO => $this->claimFromRow($row));
    }

    /** @return PageResult<BindingDTO> */
    public function searchBindings(int $scopeId, BindingSearchCriteria $criteria): PageResult
    {
        $predicate = 'b.scope_id = :scope_id';
        $params = ['scope_id' => $scopeId];
        if ($criteria->entityType !== null) {
            $predicate .= ' AND b.entity_type = :entity_type';
            $params['entity_type'] = $criteria->entityType;
        }
        if ($criteria->entityKeyPrefix !== null) {
            $predicate .= " AND b.entity_key LIKE :entity_key_prefix ESCAPE '\\\\'";
            $params['entity_key_prefix'] = $this->likePrefix($criteria->entityKeyPrefix);
        }
        if ($criteria->status !== null) {
            $predicate .= ' AND b.status = :binding_status';
            $params['binding_status'] = $criteria->status->value;
        }
        $descriptor = new PdoPaginationQueryDescriptor(
            'SELECT COUNT(*) FROM maa_slug_bindings b WHERE b.scope_id = :scope_id',
            ['scope_id' => $scopeId],
            'SELECT COUNT(*) FROM maa_slug_bindings b WHERE ' . $predicate,
            $params,
            $this->bindingSelectSql() . ' WHERE ' . $predicate,
            $params,
        );

        return $this->paginator->paginate($this->pdo, $descriptor, $criteria->pageRequest, self::bindingConfig(), fn(array $row): BindingDTO => $this->bindingFromRow($row));
    }

    /** @return PageResult<RegistryClaimDTO> */
    public function searchRegistry(int $scopeId, RegistrySearchCriteria $criteria): PageResult
    {
        $predicate = 'r.scope_id = :scope_id';
        $params = ['scope_id' => $scopeId];
        if ($criteria->slugPrefix !== null) {
            $predicate .= " AND r.slug LIKE :slug_prefix ESCAPE '\\\\'";
            $params['slug_prefix'] = $this->likePrefix($criteria->slugPrefix);
        }
        if ($criteria->role !== null) {
            $predicate .= ' AND r.claim_role = :claim_role';
            $params['claim_role'] = $criteria->role->value;
        }
        if ($criteria->bindingStatus !== null) {
            $predicate .= ' AND b.status = :binding_status';
            $params['binding_status'] = $criteria->bindingStatus->value;
        }
        $descriptor = new PdoPaginationQueryDescriptor(
            'SELECT COUNT(*) FROM maa_slug_registry r WHERE r.scope_id = :scope_id',
            ['scope_id' => $scopeId],
            'SELECT COUNT(*) FROM maa_slug_registry r INNER JOIN maa_slug_bindings b ON b.id = r.binding_id WHERE ' . $predicate,
            $params,
            $this->claimSelectSql() . ' WHERE ' . $predicate,
            $params,
        );

        return $this->paginator->paginate($this->pdo, $descriptor, $criteria->pageRequest, self::registryConfig(), fn(array $row): RegistryClaimDTO => $this->claimFromRow($row));
    }

    private function claimSelectSql(): string
    {
        return 'SELECT r.id, r.scope_id, r.binding_id, r.slug, r.claim_role, r.claimed_at, r.updated_at, '
            . 's.namespace, s.locale_key, s.context_key, s.profile_key, b.entity_type, b.entity_key, '
            . 'b.revision AS binding_revision '
            . 'FROM maa_slug_registry r INNER JOIN maa_slug_scopes s ON s.id = r.scope_id '
            . 'INNER JOIN maa_slug_bindings b ON b.id = r.binding_id';
    }

    private function bindingSelectSql(): string
    {
        return 'SELECT b.id, b.scope_id, b.entity_type, b.entity_key, b.current_registry_id, b.status, '
            . 'b.revision, b.history_sequence, b.created_at, b.updated_at, s.namespace, s.locale_key, '
            . 's.context_key, s.profile_key, cr.id AS current_claim_id, cr.slug AS current_claim_slug, '
            . 'cr.claim_role AS current_claim_role, cr.claimed_at AS current_claimed_at, '
            . 'cr.updated_at AS current_claim_updated_at FROM maa_slug_bindings b '
            . 'INNER JOIN maa_slug_scopes s ON s.id = b.scope_id '
            . 'LEFT JOIN maa_slug_registry cr ON cr.id = b.current_registry_id';
    }

    private function historySelectSql(): string
    {
        return 'SELECT h.id, h.binding_id, h.sequence_no, h.event_type, h.scope_namespace_snapshot, '
            . 'h.scope_locale_snapshot, h.scope_context_snapshot, h.entity_type_snapshot, h.entity_key_snapshot, '
            . 'h.slug_snapshot, h.previous_slug_snapshot, h.claim_role_snapshot, h.previous_claim_role_snapshot, '
            . 'h.related_namespace, h.related_locale, h.related_context, h.related_entity_type, h.related_entity_key, '
            . 'h.operation_key, h.actor_key, h.reason, h.correlation_key, h.occurred_at, h.original_occurred_at, '
            . 's.profile_key FROM maa_slug_history h INNER JOIN maa_slug_bindings b ON b.id = h.binding_id '
            . 'INNER JOIN maa_slug_scopes s ON s.id = b.scope_id';
    }

    /** @return array{0: string, 1: array<string, string|int|bool|null>} */
    private function registryPredicate(int $scopeId, ?int $bindingId, ?RegistryRoleEnum $role): array
    {
        $predicate = 'r.scope_id = :scope_id';
        $params = ['scope_id' => $scopeId];
        if ($bindingId !== null) {
            $predicate .= ' AND r.binding_id = :binding_id';
            $params['binding_id'] = $bindingId;
        }
        if ($role !== null) {
            $predicate .= ' AND r.claim_role = :claim_role';
            $params['claim_role'] = $role->value;
        }

        return [$predicate, $params];
    }

    /** @param array<string, mixed> $row */
    private function aliasFromRow(array $row): AliasDTO
    {
        $claim = $this->claimFromRow($row);
        return new AliasDTO($claim, $claim->role === RegistryRoleEnum::ACTIVE_ALIAS, $this->int($row, 'binding_revision', 0));
    }

    /** @param array<string, mixed> $row */
    private function claimFromRow(array $row): RegistryClaimDTO
    {
        $profileKey = new SlugProfileKey(PdoRowHydrator::string($row, 'profile_key'));
        $profile = $this->profiles->get($profileKey);
        $scope = new SlugScope(
            PdoRowHydrator::string($row, 'namespace'),
            $this->dimension($row, 'locale_key'),
            $this->dimension($row, 'context_key'),
        );
        $identity = new BindingIdentityDTO(
            new \Maatify\Slug\DTO\ScopeProfileRequestDTO($scope, $profileKey),
            new EntityReference(PdoRowHydrator::string($row, 'entity_type'), PdoRowHydrator::string($row, 'entity_key')),
        );
        $role = RegistryRoleEnum::tryFrom(PdoRowHydrator::string($row, 'claim_role'));
        if ($role === null) {
            throw new SlugPersistenceInvariantException('Management query returned an unknown Registry role.');
        }

        return new RegistryClaimDTO(
            PdoRowHydrator::nonNegativeInt($row, 'id'),
            $identity,
            Slug::fromProfile($profile, PdoRowHydrator::string($row, 'slug')),
            $role,
            $this->date(PdoRowHydrator::string($row, 'claimed_at'), 'registry.claimed_at'),
            $this->date(PdoRowHydrator::string($row, 'updated_at'), 'registry.updated_at'),
        );
    }

    /** @param array<string, mixed> $row */
    private function bindingFromRow(array $row): BindingDTO
    {
        $profileKey = new SlugProfileKey(PdoRowHydrator::string($row, 'profile_key'));
        $profile = $this->profiles->get($profileKey);
        $scope = new SlugScope(
            PdoRowHydrator::string($row, 'namespace'),
            $this->dimension($row, 'locale_key'),
            $this->dimension($row, 'context_key'),
        );
        $entity = new EntityReference(PdoRowHydrator::string($row, 'entity_type'), PdoRowHydrator::string($row, 'entity_key'));
        $identity = new BindingIdentityDTO(new \Maatify\Slug\DTO\ScopeProfileRequestDTO($scope, $profileKey), $entity);
        $status = BindingStatusEnum::tryFrom(PdoRowHydrator::string($row, 'status'));
        if ($status === null) {
            throw new SlugPersistenceInvariantException('Management query returned an unknown Binding status.');
        }
        $claim = null;
        if ($row['current_claim_id'] !== null) {
            $role = RegistryRoleEnum::tryFrom(PdoRowHydrator::string($row, 'current_claim_role'));
            if ($role !== RegistryRoleEnum::CURRENT_CANONICAL) {
                throw new SlugPersistenceInvariantException('Binding current pointer does not reference a current Registry claim.');
            }
            $claim = new RegistryClaimDTO(
                PdoRowHydrator::nonNegativeInt($row, 'current_claim_id'),
                $identity,
                Slug::fromProfile($profile, PdoRowHydrator::string($row, 'current_claim_slug')),
                $role,
                $this->date(PdoRowHydrator::string($row, 'current_claimed_at'), 'registry.claimed_at'),
                $this->date(PdoRowHydrator::string($row, 'current_claim_updated_at'), 'registry.updated_at'),
            );
        }

        return new BindingDTO(
            PdoRowHydrator::nonNegativeInt($row, 'id'),
            $identity,
            new BindingStateDTO($status, $claim?->slug, $this->int($row, 'revision'), $this->int($row, 'history_sequence')),
            $claim,
            $this->date(PdoRowHydrator::string($row, 'created_at'), 'binding.created_at'),
            $this->date(PdoRowHydrator::string($row, 'updated_at'), 'binding.updated_at'),
        );
    }

    /** @param array<string, mixed> $row */
    private function historyFromRow(array $row): HistoryEventDTO
    {
        $profile = $this->profiles->get(new SlugProfileKey(PdoRowHydrator::string($row, 'profile_key')));
        $eventType = HistoryEventTypeEnum::tryFrom(PdoRowHydrator::string($row, 'event_type'));
        if ($eventType === null) {
            throw new SlugPersistenceInvariantException('Management query returned an unknown History event type.');
        }
        $role = $this->role($row, 'claim_role_snapshot');
        $previousRole = $this->role($row, 'previous_claim_role_snapshot');
        $relatedScope = $row['related_namespace'] === null ? null : new SlugScope(
            PdoRowHydrator::string($row, 'related_namespace'),
            $this->nullable($row, 'related_locale'),
            $this->nullable($row, 'related_context'),
        );
        $relatedEntity = $row['related_entity_type'] === null ? null : new EntityReference(
            PdoRowHydrator::string($row, 'related_entity_type'),
            PdoRowHydrator::string($row, 'related_entity_key'),
        );

        return new HistoryEventDTO(
            PdoRowHydrator::nonNegativeInt($row, 'id'),
            PdoRowHydrator::nonNegativeInt($row, 'binding_id'),
            PdoRowHydrator::nonNegativeInt($row, 'sequence_no'),
            $eventType,
            new SlugScope(
                PdoRowHydrator::string($row, 'scope_namespace_snapshot'),
                $this->dimension($row, 'scope_locale_snapshot'),
                $this->dimension($row, 'scope_context_snapshot'),
            ),
            new EntityReference(
                PdoRowHydrator::string($row, 'entity_type_snapshot'),
                PdoRowHydrator::string($row, 'entity_key_snapshot'),
            ),
            $row['slug_snapshot'] === null ? null : Slug::fromProfile($profile, PdoRowHydrator::string($row, 'slug_snapshot')),
            $row['previous_slug_snapshot'] === null ? null : Slug::fromProfile($profile, PdoRowHydrator::string($row, 'previous_slug_snapshot')),
            $role,
            $previousRole,
            $relatedScope,
            $relatedEntity,
            $this->nullable($row, 'operation_key'),
            $this->nullable($row, 'actor_key'),
            $this->nullable($row, 'reason'),
            $this->nullable($row, 'correlation_key'),
            $this->date(PdoRowHydrator::string($row, 'occurred_at'), 'history.occurred_at'),
            $row['original_occurred_at'] === null ? null : $this->date(PdoRowHydrator::string($row, 'original_occurred_at'), 'history.original_occurred_at'),
        );
    }

    /** @param array<string, mixed> $row */
    private function role(array $row, string $field): ?RegistryRoleEnum
    {
        $value = $this->nullable($row, $field);
        if ($value === null) {
            return null;
        }
        $role = RegistryRoleEnum::tryFrom($value);
        if ($role === null) {
            throw new SlugPersistenceInvariantException(sprintf('%s contains an unknown role.', $field));
        }

        return $role;
    }

    /** @param array<string, mixed> $row */
    private function nullable(array $row, string $field): ?string
    {
        return PdoRowHydrator::nullableString($row, $field);
    }

    /** @param array<string, mixed> $row */
    private function dimension(array $row, string $field): ?string
    {
        $value = PdoRowHydrator::string($row, $field);
        return $value === '' ? null : $value;
    }

    private function likePrefix(string $prefix): string
    {
        return strtr($prefix, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
    }

    /** @param array<string, mixed> $row */
    private function int(array $row, string $field, ?int $default = null): int
    {
        if ($default !== null && ! array_key_exists($field, $row)) {
            return $default;
        }
        return PdoRowHydrator::nonNegativeInt($row, $field);
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

    private static function aliasConfig(): PaginationConfig
    {
        return new PaginationConfig(new SortWhitelist(['id' => 'r.id', 'slug' => 'r.slug', 'updated_at' => 'r.updated_at']), 'id', SortDirectionEnum::ASC, 'id', SortDirectionEnum::ASC, 25, 1, 100);
    }

    private static function historyConfig(): PaginationConfig
    {
        return new PaginationConfig(new SortWhitelist(['occurred_at' => 'h.occurred_at', 'id' => 'h.id']), 'occurred_at', SortDirectionEnum::ASC, 'id', SortDirectionEnum::ASC, 25, 1, 100);
    }

    private static function registryConfig(): PaginationConfig
    {
        return new PaginationConfig(new SortWhitelist(['id' => 'r.id', 'slug' => 'r.slug', 'role' => 'r.claim_role', 'updated_at' => 'r.updated_at']), 'id', SortDirectionEnum::ASC, 'id', SortDirectionEnum::ASC, 25, 1, 100);
    }

    private static function bindingConfig(): PaginationConfig
    {
        return new PaginationConfig(new SortWhitelist(['id' => 'b.id', 'entity_type' => 'b.entity_type', 'entity_key' => 'b.entity_key', 'updated_at' => 'b.updated_at']), 'id', SortDirectionEnum::ASC, 'id', SortDirectionEnum::ASC, 25, 1, 100);
    }
}
