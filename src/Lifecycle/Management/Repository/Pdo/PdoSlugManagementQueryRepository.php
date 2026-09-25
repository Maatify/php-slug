<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Management\Repository\Pdo;

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
use Maatify\Slug\Lifecycle\Management\Criteria\BindingSearchCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\HistoryCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\HistorySearchCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\RegistryCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\RegistrySearchCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\ScopeSearchCriteria;
use Maatify\Slug\Lifecycle\Management\Repository\ManagementQueryRepositoryInterface;
use Maatify\Slug\Lifecycle\DTO\AliasDTO;
use Maatify\Slug\Lifecycle\DTO\BindingDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\BindingStateDTO;
use Maatify\Slug\Lifecycle\DTO\HistoryEventDTO;
use Maatify\Slug\Lifecycle\DTO\RegistryClaimDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeDTO;
use Maatify\Slug\Lifecycle\Management\DTO\ScopeOperationalSummaryDTO;
use Maatify\Slug\Lifecycle\Enum\BindingStatusEnum;
use Maatify\Slug\Lifecycle\Enum\HistoryEventTypeEnum;
use Maatify\Slug\Lifecycle\Enum\RegistryRoleEnum;
use Maatify\Slug\Lifecycle\Exception\SlugPersistenceInvariantException;
use Maatify\Slug\Lifecycle\ValueObject\EntityReference;
use Maatify\Slug\Canonicalization\ValueObject\Slug;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Lifecycle\Repository\Pdo\Support\PdoRowHydrator;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistryInterface;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;

/** Direct-PDO management query adapter backed by the shared paginator. */
final readonly class PdoSlugManagementQueryRepository implements ManagementQueryRepositoryInterface
{
    private PdoPaginator $paginator;

    /** Injects PDO and profile lookup used to hydrate paginated management rows. */
    public function __construct(
        private PDO $pdo,
        private SlugProfileRegistryInterface $profiles,
    ) {
        $this->paginator = new PdoPaginator();
    }

    /**
     * Returns a page of active and retired aliases for one binding.
     *
     * @return PageResult<AliasDTO>
     */
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

    /**
     * Returns a page of history events, optionally filtered by event type.
     *
     * @return PageResult<HistoryEventDTO>
     */
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

    /**
     * Returns a page of claims within a scope, optionally narrowed by binding and role.
     *
     * @return PageResult<RegistryClaimDTO>
     */
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

    /**
     * Returns a page of bindings matching entity and lifecycle filters.
     *
     * @return PageResult<BindingDTO>
     */
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

    /**
     * Returns a page of registry claims matching slug, role, and binding filters.
     *
     * @return PageResult<RegistryClaimDTO>
     */
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

    /**
     * Returns all persisted Scopes with literal-prefix/profile filtering.
     *
     * @return PageResult<ScopeDTO>
     */
    public function searchScopes(ScopeSearchCriteria $criteria): PageResult
    {
        $predicate = '1 = 1';
        $params = [];
        if ($criteria->namespacePrefix !== null) {
            $predicate .= " AND s.namespace LIKE :namespace_prefix ESCAPE '\\\\'";
            $params['namespace_prefix'] = $this->likePrefix($criteria->namespacePrefix);
        }
        if ($criteria->profileKey !== null) {
            $predicate .= ' AND s.profile_key = :profile_key';
            $params['profile_key'] = $criteria->profileKey->value;
        }
        $descriptor = new PdoPaginationQueryDescriptor(
            'SELECT COUNT(*) FROM maa_slug_scopes s',
            [],
            'SELECT COUNT(*) FROM maa_slug_scopes s WHERE ' . $predicate,
            $params,
            $this->scopeSelectSql() . ' WHERE ' . $predicate,
            $params,
        );

        return $this->paginator->paginate($this->pdo, $descriptor, $criteria->pageRequest, self::scopeConfig(), fn(array $row): ScopeDTO => $this->scopeFromRow($row));
    }

    /** Counts current Binding, Registry, and persisted-snapshot History rows for a Scope. */
    public function getScopeOperationalSummary(ScopeDTO $scope): ScopeOperationalSummaryDTO
    {
        $bindings = $this->singleRow(
            'SELECT COUNT(*) AS total, '
            . "SUM(CASE WHEN status = 'ACTIVE' THEN 1 ELSE 0 END) AS active, "
            . "SUM(CASE WHEN status = 'INACTIVE' THEN 1 ELSE 0 END) AS inactive, "
            . "SUM(CASE WHEN status = 'RELEASED' THEN 1 ELSE 0 END) AS released "
            . 'FROM maa_slug_bindings WHERE scope_id = :scope_id',
            ['scope_id' => $scope->id],
        );
        $registry = $this->singleRow(
            'SELECT COUNT(*) AS total, '
            . "SUM(CASE WHEN claim_role = 'CURRENT_CANONICAL' THEN 1 ELSE 0 END) AS current_canonical, "
            . "SUM(CASE WHEN claim_role = 'HISTORICAL_CANONICAL' THEN 1 ELSE 0 END) AS historical_canonical, "
            . "SUM(CASE WHEN claim_role = 'ACTIVE_ALIAS' THEN 1 ELSE 0 END) AS active_aliases, "
            . "SUM(CASE WHEN claim_role = 'RETIRED_ALIAS' THEN 1 ELSE 0 END) AS retired_aliases "
            . 'FROM maa_slug_registry WHERE scope_id = :scope_id',
            ['scope_id' => $scope->id],
        );
        $history = $this->singleRow(
            'SELECT COUNT(*) AS total FROM maa_slug_history '
            . 'WHERE scope_namespace_snapshot = :namespace '
            . 'AND scope_locale_snapshot = :locale AND scope_context_snapshot = :context',
            ['namespace' => $scope->scope->namespace, 'locale' => $scope->scope->localeKey ?? '', 'context' => $scope->scope->contextKey ?? ''],
        );

        return new ScopeOperationalSummaryDTO(
            $scope,
            $this->count($bindings, 'total'),
            $this->count($bindings, 'active'),
            $this->count($bindings, 'inactive'),
            $this->count($bindings, 'released'),
            $this->count($registry, 'total'),
            $this->count($registry, 'current_canonical'),
            $this->count($registry, 'historical_canonical'),
            $this->count($registry, 'active_aliases'),
            $this->count($registry, 'retired_aliases'),
            $this->count($history, 'total'),
        );
    }

    /**
     * Returns operational History by exact persisted Scope snapshot and occurred-at window.
     *
     * @return PageResult<HistoryEventDTO>
     */
    public function searchHistory(HistorySearchCriteria $criteria): PageResult
    {
        $base = '1 = 1';
        $filtered = $base;
        $params = [];
        if ($criteria->scopeProfile !== null) {
            $base .= ' AND h.scope_namespace_snapshot = :scope_namespace '
                . 'AND h.scope_locale_snapshot = :scope_locale '
                . 'AND h.scope_context_snapshot = :scope_context '
                . 'AND s.profile_key = :scope_profile_key';
            $params += [
                'scope_namespace' => $criteria->scopeProfile->scope->namespace,
                'scope_locale' => $criteria->scopeProfile->scope->localeKey ?? '',
                'scope_context' => $criteria->scopeProfile->scope->contextKey ?? '',
                'scope_profile_key' => $criteria->scopeProfile->expectedProfileKey->value,
            ];
        }
        $filtered = $base;
        if ($criteria->eventType !== null) {
            $filtered .= ' AND h.event_type = :history_event_type';
            $params['history_event_type'] = $criteria->eventType->value;
        }
        if ($criteria->occurredFromInclusive !== null) {
            $filtered .= ' AND h.occurred_at >= :occurred_from';
            $params['occurred_from'] = $this->timestamp($criteria->occurredFromInclusive);
        }
        if ($criteria->occurredUntilExclusive !== null) {
            $filtered .= ' AND h.occurred_at < :occurred_until';
            $params['occurred_until'] = $this->timestamp($criteria->occurredUntilExclusive);
        }
        $select = $this->historySearchSelectSql();
        $descriptor = new PdoPaginationQueryDescriptor(
            'SELECT COUNT(*) FROM maa_slug_history h INNER JOIN maa_slug_scopes s '
                . 'ON s.namespace = h.scope_namespace_snapshot AND s.locale_key = h.scope_locale_snapshot AND s.context_key = h.scope_context_snapshot '
                . 'WHERE ' . $base,
            $this->historyParams($criteria),
            'SELECT COUNT(*) FROM maa_slug_history h INNER JOIN maa_slug_scopes s '
                . 'ON s.namespace = h.scope_namespace_snapshot AND s.locale_key = h.scope_locale_snapshot AND s.context_key = h.scope_context_snapshot '
                . 'WHERE ' . $filtered,
            $params,
            $select . ' WHERE ' . $filtered,
            $params,
        );

        return $this->paginator->paginate($this->pdo, $descriptor, $criteria->pageRequest, self::historySearchConfig(), fn(array $row): HistoryEventDTO => $this->historyFromRow($row));
    }

    /** @return array<string, string> */
    private function historyParams(HistorySearchCriteria $criteria): array
    {
        if ($criteria->scopeProfile === null) {
            return [];
        }
        return [
            'scope_namespace' => $criteria->scopeProfile->scope->namespace,
            'scope_locale' => $criteria->scopeProfile->scope->localeKey ?? '',
            'scope_context' => $criteria->scopeProfile->scope->contextKey ?? '',
            'scope_profile_key' => $criteria->scopeProfile->expectedProfileKey->value,
        ];
    }

    /** Returns the Scope projection used by both listing and History attribution. */
    private function scopeSelectSql(): string
    {
        return 'SELECT s.id, s.namespace, s.locale_key, s.context_key, s.profile_key, s.created_at, s.updated_at FROM maa_slug_scopes s';
    }

    /** @param array<string, mixed> $row */
    private function scopeFromRow(array $row): ScopeDTO
    {
        $profileKey = new SlugProfileKey(PdoRowHydrator::string($row, 'profile_key'));
        return new ScopeDTO(
            PdoRowHydrator::nonNegativeInt($row, 'id'),
            new SlugScope(
                PdoRowHydrator::string($row, 'namespace'),
                $this->dimension($row, 'locale_key'),
                $this->dimension($row, 'context_key'),
            ),
            $profileKey,
            $this->date(PdoRowHydrator::string($row, 'created_at'), 'scope.created_at'),
            $this->date(PdoRowHydrator::string($row, 'updated_at'), 'scope.updated_at'),
        );
    }

    /** @param array<string, string|int|bool|null> $params
     *  @return array<string, mixed>
     */
    private function singleRow(string $sql, array $params): array
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare the operational summary query.');
        }
        $statement->execute($params);
        $row = PdoRowHydrator::one($statement->fetch(PDO::FETCH_ASSOC));
        if ($row === null) {
            throw new SlugPersistenceInvariantException('Operational summary query returned no aggregate row.');
        }
        return $row;
    }

    /** @param array<string, mixed> $row */
    private function count(array $row, string $field): int
    {
        return $row[$field] === null ? 0 : PdoRowHydrator::nonNegativeInt($row, $field);
    }

    /** Formats DateTime inputs into the package's UTC DATETIME(6) comparison value. */
    private function timestamp(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    /** Returns History projection joined to the Scope represented by its persisted snapshot. */
    private function historySearchSelectSql(): string
    {
        return 'SELECT h.id, h.binding_id, h.sequence_no, h.event_type, h.scope_namespace_snapshot, '
            . 'h.scope_locale_snapshot, h.scope_context_snapshot, h.entity_type_snapshot, h.entity_key_snapshot, '
            . 'h.slug_snapshot, h.previous_slug_snapshot, h.claim_role_snapshot, h.previous_claim_role_snapshot, '
            . 'h.related_namespace, h.related_locale, h.related_context, h.related_entity_type, h.related_entity_key, '
            . 'h.operation_key, h.actor_key, h.reason, h.correlation_key, h.occurred_at, h.original_occurred_at, '
            . 's.profile_key FROM maa_slug_history h INNER JOIN maa_slug_scopes s '
            . 'ON s.namespace = h.scope_namespace_snapshot AND s.locale_key = h.scope_locale_snapshot AND s.context_key = h.scope_context_snapshot';
    }

    /** Returns the shared claim query projection and joins. */
    private function claimSelectSql(): string
    {
        return 'SELECT r.id, r.scope_id, r.binding_id, r.slug, r.claim_role, r.claimed_at, r.updated_at, '
            . 's.namespace, s.locale_key, s.context_key, s.profile_key, b.entity_type, b.entity_key, '
            . 'b.revision AS binding_revision '
            . 'FROM maa_slug_registry r INNER JOIN maa_slug_scopes s ON s.id = r.scope_id '
            . 'INNER JOIN maa_slug_bindings b ON b.id = r.binding_id';
    }

    /** Returns the binding query projection, including its current claim. */
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

    /** Returns the history query projection and scope join. */
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
            new \Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO($scope, $profileKey),
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
        $identity = new BindingIdentityDTO(new \Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO($scope, $profileKey), $entity);
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

    /** Escapes LIKE metacharacters before applying a prefix wildcard. */
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

    /** Parses the repository's six-microsecond UTC timestamp representation. */
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

    /** Defines stable sorting and limits for alias pages. */
    private static function aliasConfig(): PaginationConfig
    {
        return new PaginationConfig(new SortWhitelist(['id' => 'r.id', 'slug' => 'r.slug', 'updated_at' => 'r.updated_at']), 'id', SortDirectionEnum::ASC, 'id', SortDirectionEnum::ASC, 25, 1, 100);
    }

    /** Defines stable sorting and limits for history pages. */
    private static function historyConfig(): PaginationConfig
    {
        return new PaginationConfig(new SortWhitelist(['occurred_at' => 'h.occurred_at', 'id' => 'h.id']), 'occurred_at', SortDirectionEnum::ASC, 'id', SortDirectionEnum::ASC, 25, 1, 100);
    }

    /** Defines operational History sorting with newest events first. */
    private static function historySearchConfig(): PaginationConfig
    {
        return new PaginationConfig(new SortWhitelist(['occurred_at' => 'h.occurred_at', 'id' => 'h.id']), 'occurred_at', SortDirectionEnum::DESC, 'id', SortDirectionEnum::DESC, 25, 1, 100);
    }

    /** Defines Scope listing sorting and the required deterministic id tie-breaker. */
    private static function scopeConfig(): PaginationConfig
    {
        return new PaginationConfig(new SortWhitelist([
            'id' => 's.id',
            'namespace' => 's.namespace',
            'locale_key' => 's.locale_key',
            'context_key' => 's.context_key',
            'profile_key' => 's.profile_key',
            'updated_at' => 's.updated_at',
        ]), 'id', SortDirectionEnum::ASC, 'id', SortDirectionEnum::ASC, 25, 1, 100);
    }

    /** Defines stable sorting and limits for registry pages. */
    private static function registryConfig(): PaginationConfig
    {
        return new PaginationConfig(new SortWhitelist(['id' => 'r.id', 'slug' => 'r.slug', 'role' => 'r.claim_role', 'updated_at' => 'r.updated_at']), 'id', SortDirectionEnum::ASC, 'id', SortDirectionEnum::ASC, 25, 1, 100);
    }

    /** Defines stable sorting and limits for binding pages. */
    private static function bindingConfig(): PaginationConfig
    {
        return new PaginationConfig(new SortWhitelist(['id' => 'b.id', 'entity_type' => 'b.entity_type', 'entity_key' => 'b.entity_key', 'updated_at' => 'b.updated_at']), 'id', SortDirectionEnum::ASC, 'id', SortDirectionEnum::ASC, 25, 1, 100);
    }
}
