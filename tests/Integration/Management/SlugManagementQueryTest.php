<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Integration\Management;

use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Slug\Lifecycle\Command\AddAliasCommand;
use Maatify\Slug\Lifecycle\Command\AssignExactCommand;
use Maatify\Slug\Lifecycle\Command\ChangeExactCommand;
use Maatify\Slug\Lifecycle\Command\DeactivateBindingCommand;
use Maatify\Slug\Lifecycle\Command\ReactivateBindingCommand;
use Maatify\Slug\Lifecycle\Command\RetireAliasCommand;
use Maatify\Slug\Lifecycle\Command\TransitionScopeCommand;
use Maatify\Slug\Lifecycle\Command\ReleaseAllOwnershipCommand;
use Maatify\Slug\Lifecycle\Management\Criteria\AliasCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\BindingCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\BindingSearchCriteria;
use Maatify\Slug\Lifecycle\Criteria\CurrentSlugCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\HistoryCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\RegistryCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\RegistrySearchCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\ScopeCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\ScopeSearchCriteria;
use Maatify\Slug\Lifecycle\Management\Criteria\HistorySearchCriteria;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeTransitionClaimIntentDTO;
use Maatify\Slug\Facade\SlugEngine;
use Maatify\Slug\Factory\SlugEngineFactory;
use Maatify\Slug\Lifecycle\Enum\HistoryEventTypeEnum;
use Maatify\Slug\Lifecycle\Enum\ClaimIntentModeEnum;
use Maatify\Slug\Lifecycle\Enum\RegistryRoleEnum;
use Maatify\Slug\Lifecycle\Enum\ScopeTransitionModeEnum;
use Maatify\Slug\Lifecycle\Exception\SlugScopeProfileMismatchException;
use Maatify\Slug\Lifecycle\ValueObject\EntityReference;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistry;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;
use Maatify\Slug\Tests\Integration\Schema\MySqlIntegrationTestCase;
use Maatify\Slug\Tests\Unit\Allocation\TestReservedSlugPolicy;
use Maatify\Slug\Tests\Unit\Allocation\TestSlugProfile;

final class SlugManagementQueryTest extends MySqlIntegrationTestCase
{
    public function testManagementReadsUseSharedPaginationAndPackageOwnedJoins(): void
    {
        $engine = $this->engine();
        $identity = $this->identity('management-1');
        $engine->assignExact(new AssignExactCommand($identity, 'main-slug', null, new AuditContextDTO()));
        $engine->addAlias(new AddAliasCommand($identity, 'alias-one', 1, new AuditContextDTO()));
        $engine->addAlias(new AddAliasCommand($identity, 'alias-two', 2, new AuditContextDTO()));
        $engine->changeExact(new ChangeExactCommand($identity, 'changed-slug', 3, new AuditContextDTO()));

        $aliases = $engine->listAliases(new AliasCriteria($identity, new PageRequest(1, 1, 'slug', 'ASC')));
        self::assertSame(2, $aliases->total);
        self::assertSame(2, $aliases->filtered);
        self::assertCount(1, $aliases->data);
        self::assertSame('alias-one', $aliases->data[0]->claim->slug->value);
        self::assertSame(25, $engine->listAliases(new AliasCriteria($identity, new PageRequest()))->perPage);

        $history = $engine->getHistory(new HistoryCriteria($identity, new PageRequest(1, 10), HistoryEventTypeEnum::ASSIGNED));
        self::assertSame(4, $history->total);
        self::assertSame(1, $history->filtered);
        self::assertSame('ASSIGNED', $history->data[0]->eventType->value);

        $binding = $engine->getBinding(new BindingCriteria($identity));
        self::assertNotNull($binding);
        self::assertSame('changed-slug', $binding->state->currentSlug?->value);
        self::assertSame('changed-slug', $engine->getCurrent(new CurrentSlugCriteria($identity))?->claim->slug->value);
        self::assertSame('catalog', $engine->inspectScope(new ScopeCriteria($identity->scopeProfile))?->scope->namespace);

        $registry = $engine->inspectRegistry(new RegistryCriteria(
            $identity->scopeProfile,
            new PageRequest(1, 10, 'id', 'ASC'),
        ));
        self::assertSame(4, $registry->total);
        self::assertSame(4, $registry->filtered);
        self::assertSame(RegistryRoleEnum::CURRENT_CANONICAL, $registry->data[3]->role);

        $bindings = $engine->searchBindings(new BindingSearchCriteria(
            $identity->scopeProfile,
            new PageRequest(1, 10),
            entityType: 'product',
            entityKeyPrefix: 'management-',
        ));
        self::assertSame(1, $bindings->filtered);
        self::assertSame('management-1', $bindings->data[0]->identity->entity->entityKey);

        $searched = $engine->searchRegistry(new RegistrySearchCriteria(
            $identity->scopeProfile,
            new PageRequest(1, 10),
            slugPrefix: 'alias-',
            role: RegistryRoleEnum::ACTIVE_ALIAS,
        ));
        self::assertSame(2, $searched->filtered);
    }

    public function testScopeDiscoverySummaryAndOperationalHistoryUsePersistedBoundaries(): void
    {
        $engine = $this->engine();
        $primary = $this->identity('reporting-primary');
        $inactive = $this->identity('reporting-inactive');
        $released = $this->identity('reporting-released');

        $engine->assignExact(new AssignExactCommand($primary, 'reporting-main', null, new AuditContextDTO()));
        $engine->addAlias(new AddAliasCommand($primary, 'reporting-alias', 1, new AuditContextDTO()));
        $engine->changeExact(new ChangeExactCommand($primary, 'reporting-current', 2, new AuditContextDTO()));
        $withActiveAlias = $engine->getScopeOperationalSummary(new ScopeCriteria($primary->scopeProfile));
        self::assertNotNull($withActiveAlias);
        self::assertSame(1, $withActiveAlias->registryActiveAliases);
        $engine->retireAlias(new RetireAliasCommand($primary, 'reporting-alias', 3, new AuditContextDTO()));
        $engine->assignExact(new AssignExactCommand($inactive, 'reporting-inactive', null, new AuditContextDTO()));
        $engine->deactivate(new DeactivateBindingCommand($inactive, 1, new AuditContextDTO()));
        $engine->assignExact(new AssignExactCommand($released, 'reporting-released', null, new AuditContextDTO()));
        $engine->releaseAllOwnership(new ReleaseAllOwnershipCommand($released, 1, new AuditContextDTO()));

        $scopes = $engine->searchScopes(new ScopeSearchCriteria(new PageRequest(1, 1), 'cat'));
        self::assertSame(1, $scopes->total);
        self::assertSame(1, $scopes->filtered);
        self::assertSame('catalog', $scopes->data[0]->scope->namespace);

        $summary = $engine->getScopeOperationalSummary(new ScopeCriteria($primary->scopeProfile));
        self::assertNotNull($summary);
        self::assertSame(3, $summary->bindingsTotal);
        self::assertSame(1, $summary->bindingsActive);
        self::assertSame(1, $summary->bindingsInactive);
        self::assertSame(1, $summary->bindingsReleased);
        self::assertSame(4, $summary->registryClaimsTotal);
        self::assertSame(2, $summary->registryCurrentCanonical);
        self::assertSame(1, $summary->registryHistoricalCanonical);
        self::assertSame(0, $summary->registryActiveAliases);
        self::assertSame(1, $summary->registryRetiredAliases);
        self::assertSame(9, $summary->historyEventsTotal);

        $reactivated = $engine->reactivate(new ReactivateBindingCommand($inactive, 2, new AuditContextDTO()));
        self::assertSame('ACTIVE', $reactivated->after->state->status->value);
        $afterReactivation = $engine->getScopeOperationalSummary(new ScopeCriteria($primary->scopeProfile));
        self::assertNotNull($afterReactivation);
        self::assertSame(2, $afterReactivation->bindingsActive);
        self::assertSame(0, $afterReactivation->bindingsInactive);
        self::assertSame(1, $afterReactivation->bindingsReleased);

        $history = $engine->searchHistory(new HistorySearchCriteria(new PageRequest(1, 100), $primary->scopeProfile));
        self::assertSame(10, $history->total);
        self::assertSame(10, $history->filtered);
        self::assertSame('occurred_at', $history->sortBy);
        self::assertSame('DESC', $history->sortDirection->value);
        self::assertCount(10, $history->data);

        $missing = new ScopeProfileRequestDTO(new SlugScope('missing-reporting-scope', null, null), new SlugProfileKey('ascii-v1'));
        $empty = $engine->searchHistory(new HistorySearchCriteria(new PageRequest(1, 10), $missing));
        self::assertSame(0, $empty->total);
        self::assertSame(0, $empty->filtered);
        self::assertNull($engine->getScopeOperationalSummary(new ScopeCriteria($missing)));
    }

    public function testScopeSearchProvesSqlLiteralFiltersProfilesSortingIsolationAndEmptyResults(): void
    {
        $engine = $this->engineWithProfiles();
        /** @var array<string, BindingIdentityDTO> $dimensioned */
        $dimensioned = [];
        foreach ([
            ['scope-z', null, null, 'ascii-v1'],
            ['scope-a', 'ar', 'store', 'ascii-v1'],
            ['scope-a', 'en', 'web', 'ascii-v1'],
            ['custom-scope', null, null, 'custom-v1'],
        ] as [$namespace, $locale, $context, $profile]) {
            $identity = $this->identityInScope((string) $namespace, $locale, $context, (string) $profile);
            $engine->assignExact(new AssignExactCommand($identity, 'scope-' . str_replace('-', '', (string) $namespace), null, new AuditContextDTO()));
            if ($namespace === 'scope-a') {
                $dimensioned[$locale] = $identity;
            }
        }

        $profile = new SlugProfileKey('custom-v1');
        $custom = $engine->searchScopes(new ScopeSearchCriteria(new PageRequest(1, 10), null, $profile));
        self::assertSame(4, $custom->total);
        self::assertSame(1, $custom->filtered);
        self::assertSame('custom-v1', $custom->data[0]->profileKey->value);

        foreach (['scope%', 'scope_', 'scope\\'] as $literalPrefix) {
            $result = $engine->searchScopes(new ScopeSearchCriteria(new PageRequest(1, 10), $literalPrefix));
            self::assertSame(0, $result->filtered, $literalPrefix);
        }
        $empty = $engine->searchScopes(new ScopeSearchCriteria(new PageRequest(1, 10), 'does-not-exist'));
        self::assertSame(4, $empty->total);
        self::assertSame(0, $empty->filtered);

        $baseline = $engine->searchScopes(new ScopeSearchCriteria(new PageRequest(1, 10, 'id', 'ASC')));
        foreach (['namespace', 'locale_key', 'context_key', 'profile_key', 'updated_at', 'id'] as $sortBy) {
            $page = $engine->searchScopes(new ScopeSearchCriteria(new PageRequest(1, 10, $sortBy, 'ASC')));
            self::assertSame($sortBy, $page->sortBy);
            self::assertSame('ASC', $page->sortDirection->value);
            $expected = $baseline->data;
            usort($expected, static function ($left, $right) use ($sortBy): int {
                $leftValue = match ($sortBy) {
                    'namespace' => $left->scope->namespace,
                    'locale_key' => $left->scope->localeKey ?? '',
                    'context_key' => $left->scope->contextKey ?? '',
                    'profile_key' => $left->profileKey->value,
                    'updated_at' => $left->updatedAt->format('Y-m-d H:i:s.u'),
                    'id' => $left->id,
                };
                $rightValue = match ($sortBy) {
                    'namespace' => $right->scope->namespace,
                    'locale_key' => $right->scope->localeKey ?? '',
                    'context_key' => $right->scope->contextKey ?? '',
                    'profile_key' => $right->profileKey->value,
                    'updated_at' => $right->updatedAt->format('Y-m-d H:i:s.u'),
                    'id' => $right->id,
                };
                return $leftValue <=> $rightValue ?: $left->id <=> $right->id;
            });
            self::assertSame(array_map(static fn($scope): int => $scope->id, $expected), array_map(static fn($scope): int => $scope->id, $page->data), $sortBy);
        }
        $updated = $engine->searchScopes(new ScopeSearchCriteria(new PageRequest(1, 10, 'updated_at', 'ASC')));
        self::assertSame($updated->data[0]->updatedAt->format('Y-m-d H:i:s.u'), $updated->data[1]->updatedAt->format('Y-m-d H:i:s.u'));
        self::assertLessThan($updated->data[1]->id, $updated->data[0]->id);

        $sorted = $engine->searchScopes(new ScopeSearchCriteria(new PageRequest(1, 10, 'namespace', 'ASC')));
        self::assertSame(['custom-scope', 'scope-a', 'scope-a', 'scope-z'], array_map(static fn($scope): string => $scope->scope->namespace, $sorted->data));
        self::assertSame('ar', $sorted->data[1]->scope->localeKey);
        self::assertSame('store', $sorted->data[1]->scope->contextKey);
        self::assertSame('en', $sorted->data[2]->scope->localeKey);
        self::assertSame('web', $sorted->data[2]->scope->contextKey);

        $firstPage = $engine->searchScopes(new ScopeSearchCriteria(new PageRequest(1, 2, 'id', 'ASC')));
        $secondPage = $engine->searchScopes(new ScopeSearchCriteria(new PageRequest(2, 2, 'id', 'ASC')));
        self::assertTrue($firstPage->hasNext);
        self::assertFalse($firstPage->hasPrevious);
        self::assertFalse($secondPage->hasNext);
        self::assertTrue($secondPage->hasPrevious);
        self::assertSame(2, $secondPage->page);
        self::assertSame(
            $sortedIds = array_map(static fn($scope): int => $scope->id, $engine->searchScopes(new ScopeSearchCriteria(new PageRequest(1, 10, 'id', 'ASC')))->data),
            array_merge(
                array_map(static fn($scope): int => $scope->id, $firstPage->data),
                array_map(static fn($scope): int => $scope->id, $secondPage->data),
            ),
        );

        $arSummary = $engine->getScopeOperationalSummary(new ScopeCriteria($dimensioned['ar']->scopeProfile));
        $enSummary = $engine->getScopeOperationalSummary(new ScopeCriteria($dimensioned['en']->scopeProfile));
        self::assertNotNull($arSummary);
        self::assertNotNull($enSummary);
        self::assertSame(1, $arSummary->bindingsTotal);
        self::assertSame(1, $enSummary->bindingsTotal);
        self::assertSame(1, $arSummary->historyEventsTotal);
        self::assertSame(1, $enSummary->historyEventsTotal);
        self::assertNotSame($arSummary->scope->id, $enSummary->scope->id);
    }

    public function testOperationalHistorySupportsPackageWideWindowsAndTransitionSnapshots(): void
    {
        $engine = $this->engine();
        $source = $this->identityInScope('history-source', null, null, 'ascii-v1');
        $targetScope = new ScopeProfileRequestDTO(new SlugScope('history-target', null, null), new SlugProfileKey('ascii-v1'));
        $engine->assignExact(new AssignExactCommand($source, 'history-main', null, new AuditContextDTO()));
        $transition = $engine->transitionScope(new TransitionScopeCommand(
            $source,
            $targetScope,
            ScopeTransitionModeEnum::MOVE,
            new ScopeTransitionClaimIntentDTO(ClaimIntentModeEnum::EXACT, 'history-moved'),
            1,
            new AuditContextDTO(),
        ));
        self::assertNotEmpty($transition->historyEvents);

        $sourceHistory = $engine->searchHistory(new HistorySearchCriteria(new PageRequest(1, 10, 'occurred_at', 'ASC'), $source->scopeProfile));
        $targetHistory = $engine->searchHistory(new HistorySearchCriteria(new PageRequest(1, 10), $targetScope));
        self::assertSame(2, $sourceHistory->filtered);
        self::assertSame(1, $targetHistory->filtered);
        self::assertSame(HistoryEventTypeEnum::SCOPE_TRANSITIONED_OUT, $sourceHistory->data[0]->eventType);
        self::assertSame(HistoryEventTypeEnum::SCOPE_TRANSITIONED_IN, $targetHistory->data[0]->eventType);

        $packageWide = $engine->searchHistory(new HistorySearchCriteria(new PageRequest(1, 10)));
        self::assertSame(3, $packageWide->total);
        self::assertSame(3, $packageWide->filtered);
        $sourceBinding = $engine->getBinding(new BindingCriteria($source));
        self::assertNotNull($sourceBinding);
        $assign = $this->pdo->prepare("UPDATE maa_slug_history SET occurred_at = '2026-01-01 00:00:00.123456' WHERE binding_id = :binding_id AND sequence_no = 1");
        self::assertNotFalse($assign);
        $assign->execute(['binding_id' => $sourceBinding->id]);
        $eventTimes = [
            $transition->historyEvents[0]->id => '2026-01-01 00:00:00.123457',
            $transition->historyEvents[1]->id => '2026-01-01 00:00:00.123458',
        ];
        $eventUpdate = $this->pdo->prepare('UPDATE maa_slug_history SET occurred_at = :occurred_at WHERE id = :id');
        self::assertNotFalse($eventUpdate);
        foreach ($eventTimes as $eventId => $occurredAt) {
            $eventUpdate->execute(['id' => $eventId, 'occurred_at' => $occurredAt]);
        }

        $utcWindow = $engine->searchHistory(new HistorySearchCriteria(
            new PageRequest(1, 10, 'occurred_at', 'ASC'),
            null,
            null,
            new \DateTimeImmutable('2026-01-01T00:00:00.123457Z'),
            new \DateTimeImmutable('2026-01-01T00:00:00.123458Z'),
        ));
        $equivalentTimezoneWindow = $engine->searchHistory(new HistorySearchCriteria(
            new PageRequest(1, 10, 'occurred_at', 'ASC'),
            null,
            null,
            new \DateTimeImmutable('2026-01-01T02:00:00.123457+02:00'),
            new \DateTimeImmutable('2026-01-01T02:00:00.123458+02:00'),
        ));
        self::assertSame(3, $utcWindow->total);
        self::assertSame(1, $utcWindow->filtered);
        self::assertSame($transition->historyEvents[0]->id, $utcWindow->data[0]->id);
        self::assertSame($utcWindow->total, $equivalentTimezoneWindow->total);
        self::assertSame($utcWindow->filtered, $equivalentTimezoneWindow->filtered);
        self::assertSame(
            array_map(static fn($event): int => $event->id, $utcWindow->data),
            array_map(static fn($event): int => $event->id, $equivalentTimezoneWindow->data),
        );
        self::assertSame($transition->historyEvents[1]->id, $engine->searchHistory(new HistorySearchCriteria(
            new PageRequest(1, 10),
            null,
            null,
            new \DateTimeImmutable('2026-01-01T00:00:00.123458Z'),
            new \DateTimeImmutable('2026-01-01T00:00:00.123459Z'),
        ))->data[0]->id);
    }

    public function testOperationalHistoryUsesPersistedSnapshotAfterCurrentBindingScopeMoves(): void
    {
        $engine = $this->engine();
        $source = $this->identityInScope('snapshot-source', null, null, 'ascii-v1');
        $relocatedScope = new ScopeProfileRequestDTO(new SlugScope('snapshot-relocated', null, null), new SlugProfileKey('ascii-v1'));
        $engine->assignExact(new AssignExactCommand($source, 'snapshot-main', null, new AuditContextDTO()));
        $engine->releaseAllOwnership(new ReleaseAllOwnershipCommand($source, 1, new AuditContextDTO()));
        $sourceBinding = $engine->getBinding(new BindingCriteria($source));
        self::assertNotNull($sourceBinding);
        $relocated = $engine->ensureScope($relocatedScope);

        $moveBinding = $this->pdo->prepare('UPDATE maa_slug_bindings SET scope_id = :scope_id WHERE id = :binding_id');
        self::assertNotFalse($moveBinding);
        $moveBinding->execute(['scope_id' => $relocated->id, 'binding_id' => $sourceBinding->id]);

        $sourceHistory = $engine->searchHistory(new HistorySearchCriteria(new PageRequest(1, 10), $source->scopeProfile));
        $relocatedHistory = $engine->searchHistory(new HistorySearchCriteria(new PageRequest(1, 10), $relocatedScope));
        self::assertGreaterThan(0, $sourceHistory->filtered);
        self::assertSame(0, $relocatedHistory->filtered);
        self::assertSame(
            ['ASSIGNED', 'OWNERSHIP_RELEASED_ALL'],
            array_map(static fn($event): string => $event->eventType->value, $sourceHistory->data),
        );
    }

    public function testOperationalHistoryUsesHalfOpenUtcMicrosecondWindowAndFilters(): void
    {
        $engine = $this->engine();
        $identity = $this->identity('reporting-window');
        $engine->assignExact(new AssignExactCommand($identity, 'window-main', null, new AuditContextDTO()));
        $engine->changeExact(new ChangeExactCommand($identity, 'window-current', 1, new AuditContextDTO()));
        $binding = $engine->getBinding(new BindingCriteria($identity));
        self::assertNotNull($binding);
        $statement = $this->pdo->prepare("UPDATE maa_slug_history SET occurred_at = CASE sequence_no WHEN 1 THEN '2026-01-01 00:00:00.000001' ELSE '2026-01-01 00:00:00.000002' END WHERE binding_id = :binding_id");
        self::assertNotFalse($statement);
        $statement->execute(['binding_id' => $binding->id]);

        $from = new \DateTimeImmutable('2026-01-01T02:00:00.000001+02:00');
        $until = new \DateTimeImmutable('2026-01-01T00:00:00.000002Z');
        $page = $engine->searchHistory(new HistorySearchCriteria(new PageRequest(1, 10, 'occurred_at', 'ASC'), $identity->scopeProfile, HistoryEventTypeEnum::ASSIGNED, $from, $until));
        self::assertSame(2, $page->total);
        self::assertSame(1, $page->filtered);
        self::assertSame(1, $page->data[0]->sequenceNo);
    }

    public function testManagementPrefixFiltersTreatWildcardAndEscapeCharactersAsLiteral(): void
    {
        $engine = $this->engine();
        foreach ([
            'literal%owner' => 'percent-slug',
            'literal_owner' => 'underscore-slug',
            'literal-escape' => 'escape-slug',
            'literalXowner' => 'ordinary-slug',
        ] as $entityKey => $slug) {
            $engine->assignExact(new AssignExactCommand($this->identity($entityKey), $slug, null, new AuditContextDTO()));
        }

        $percent = $engine->searchBindings(new BindingSearchCriteria(
            $this->identity('literal%owner')->scopeProfile,
            new PageRequest(1, 1, 'entity_key', 'ASC'),
            entityType: 'product',
            entityKeyPrefix: 'literal%',
        ));
        self::assertSame(1, $percent->filtered);
        self::assertSame('literal%owner', $percent->data[0]->identity->entity->entityKey);

        $underscore = $engine->searchBindings(new BindingSearchCriteria(
            $this->identity('literal_owner')->scopeProfile,
            new PageRequest(1, 10),
            entityKeyPrefix: 'literal_',
        ));
        self::assertSame(1, $underscore->filtered);
        self::assertSame('literal_owner', $underscore->data[0]->identity->entity->entityKey);

        $escape = $engine->searchBindings(new BindingSearchCriteria(
            $this->identity('literal-escape')->scopeProfile,
            new PageRequest(1, 10),
            entityKeyPrefix: 'literal\\',
        ));
        self::assertSame(0, $escape->filtered);
        self::assertCount(0, $escape->data);

        $ordinary = $engine->searchBindings(new BindingSearchCriteria(
            $this->identity('literalXowner')->scopeProfile,
            new PageRequest(1, 10),
            entityKeyPrefix: 'literalX',
        ));
        self::assertSame(1, $ordinary->filtered);

        $wildcardSlug = $engine->searchRegistry(new RegistrySearchCriteria(
            $this->identity('literal%owner')->scopeProfile,
            new PageRequest(1, 10),
            slugPrefix: 'percent%',
        ));
        self::assertSame(0, $wildcardSlug->filtered);
        $normalSlug = $engine->searchRegistry(new RegistrySearchCriteria(
            $this->identity('literal%owner')->scopeProfile,
            new PageRequest(1, 10),
            slugPrefix: 'percent-',
        ));
        self::assertSame(1, $normalSlug->filtered);
    }

    public function testRegistryCriteriaRejectsBindingFromAnotherScope(): void
    {
        $engine = $this->engine();
        $binding = $this->identity('scope-consistency');
        $engine->assignExact(new AssignExactCommand($binding, 'scope-consistency-slug', null, new AuditContextDTO()));

        $requestedScope = new ScopeProfileRequestDTO(new SlugScope('another-catalog', null, null), new SlugProfileKey('ascii-v1'));
        $this->expectException(SlugScopeProfileMismatchException::class);
        $engine->inspectRegistry(new RegistryCriteria($requestedScope, new PageRequest(), $binding));
    }

    public function testRegistryCriteriaRejectsAbsentBindingFromAnotherScopeBeforeLookup(): void
    {
        $engine = $this->engine();
        $absentBinding = new BindingIdentityDTO(
            new ScopeProfileRequestDTO(new SlugScope('absent-binding-scope', null, null), new SlugProfileKey('ascii-v1')),
            new EntityReference('product', 'absent-binding'),
        );
        $requestedScope = new ScopeProfileRequestDTO(new SlugScope('requested-scope', null, null), new SlugProfileKey('ascii-v1'));

        $this->expectException(SlugScopeProfileMismatchException::class);
        $engine->inspectRegistry(new RegistryCriteria($requestedScope, new PageRequest(), $absentBinding));
    }

    public function testManagementSortAndPageConfigurationUsesSharedPaginator(): void
    {
        $engine = $this->engine();
        $identity = $this->identity('management-pages');
        $engine->assignExact(new AssignExactCommand($identity, 'page-main', null, new AuditContextDTO()));
        $engine->addAlias(new AddAliasCommand($identity, 'page-alias-a', 1, new AuditContextDTO()));
        $engine->addAlias(new AddAliasCommand($identity, 'page-alias-b', 2, new AuditContextDTO()));
        $engine->changeExact(new ChangeExactCommand($identity, 'page-current', 3, new AuditContextDTO()));

        foreach (['id', 'slug', 'updated_at'] as $sortBy) {
            foreach (['ASC', 'DESC'] as $direction) {
                $page = $engine->listAliases(new AliasCriteria($identity, new PageRequest(1, 1, $sortBy, $direction)));
                self::assertSame($sortBy, $page->sortBy);
                self::assertSame($direction, $page->sortDirection->value);
            }
        }
        foreach (['occurred_at', 'id'] as $sortBy) {
            $page = $engine->getHistory(new HistoryCriteria($identity, new PageRequest(1, 1, $sortBy, 'DESC')));
            self::assertSame($sortBy, $page->sortBy);
            self::assertSame('DESC', $page->sortDirection->value);
        }
        foreach (['id', 'slug', 'role', 'updated_at'] as $sortBy) {
            $page = $engine->inspectRegistry(new RegistryCriteria($identity->scopeProfile, new PageRequest(1, 1, $sortBy, 'DESC')));
            self::assertSame($sortBy, $page->sortBy);
        }
        foreach (['id', 'entity_type', 'entity_key', 'updated_at'] as $sortBy) {
            $page = $engine->searchBindings(new BindingSearchCriteria($identity->scopeProfile, new PageRequest(1, 1, $sortBy, 'DESC')));
            self::assertSame($sortBy, $page->sortBy);
        }
        foreach (['id', 'slug', 'role', 'updated_at'] as $sortBy) {
            $page = $engine->searchRegistry(new RegistrySearchCriteria($identity->scopeProfile, new PageRequest(1, 1, $sortBy, 'DESC')));
            self::assertSame($sortBy, $page->sortBy);
        }

        self::assertSame(25, $engine->searchBindings(new BindingSearchCriteria($identity->scopeProfile, new PageRequest()))->perPage);
        self::assertSame(1, $engine->searchBindings(new BindingSearchCriteria($identity->scopeProfile, new PageRequest(1, 0)))->perPage);
        self::assertSame(100, $engine->searchBindings(new BindingSearchCriteria($identity->scopeProfile, new PageRequest(1, 1000)))->perPage);
        $last = $engine->listAliases(new AliasCriteria($identity, new PageRequest(2, 1, 'id', 'ASC')));
        self::assertFalse($last->hasNext);
        self::assertTrue($last->hasPrevious);
        self::assertSame(2, $last->page);
        self::assertSame(['page-alias-b'], array_map(static fn($alias): string => $alias->claim->slug->value, $last->data));
    }

    public function testHistoryRemainsAfterReleaseAndRegistryReusesSlugForNewBinding(): void
    {
        $engine = $this->engine();
        $old = $this->identity('released-owner');
        $engine->assignExact(new AssignExactCommand($old, 'reusable-slug', null, new AuditContextDTO()));
        $released = $engine->releaseAllOwnership(new ReleaseAllOwnershipCommand($old, 1, new AuditContextDTO()));
        self::assertSame('RELEASED', $released->after->state->status->value);

        $history = $engine->getHistory(new HistoryCriteria($old, new PageRequest(1, 10)));
        self::assertSame(3, $history->filtered);
        $live = $engine->inspectRegistry(new RegistryCriteria($old->scopeProfile, new PageRequest()));
        self::assertSame(0, $live->filtered);

        $new = $this->identity('reused-owner');
        $engine->assignExact(new AssignExactCommand($new, 'reusable-slug', null, new AuditContextDTO()));
        $current = $engine->searchRegistry(new RegistrySearchCriteria($old->scopeProfile, new PageRequest(), 'reusable-slug'));
        self::assertSame(1, $current->filtered);
        self::assertSame('reused-owner', $current->data[0]->binding->entity->entityKey);
    }

    public function testManagementPagesExposeEmptyResultsAndIsolateScopes(): void
    {
        $engine = $this->engine();
        $catalog = $this->identity('isolated-catalog');
        $otherScope = new BindingIdentityDTO(
            new ScopeProfileRequestDTO(new SlugScope('isolated-other', null, null), new SlugProfileKey('ascii-v1')),
            new EntityReference('product', 'isolated-other'),
        );
        $engine->assignExact(new AssignExactCommand($catalog, 'isolated-catalog-slug', null, new AuditContextDTO()));
        $engine->assignExact(new AssignExactCommand($otherScope, 'isolated-other-slug', null, new AuditContextDTO()));

        $emptyAliases = $engine->listAliases(new AliasCriteria($catalog, new PageRequest(1, 1)));
        self::assertSame(0, $emptyAliases->total);
        self::assertSame(0, $emptyAliases->filtered);
        self::assertCount(0, $emptyAliases->data);
        self::assertFalse($emptyAliases->hasNext);
        self::assertFalse($emptyAliases->hasPrevious);

        $catalogBindings = $engine->searchBindings(new BindingSearchCriteria(
            $catalog->scopeProfile,
            new PageRequest(1, 10),
            entityType: 'product',
            entityKeyPrefix: 'isolated-',
        ));
        self::assertSame(1, $catalogBindings->total);
        self::assertSame(1, $catalogBindings->filtered);
        self::assertSame('isolated-catalog', $catalogBindings->data[0]->identity->entity->entityKey);

        $catalogRegistry = $engine->inspectRegistry(new RegistryCriteria($catalog->scopeProfile, new PageRequest()));
        self::assertSame(1, $catalogRegistry->total);
        self::assertSame(1, $catalogRegistry->filtered);
        $otherRegistry = $engine->searchRegistry(new RegistrySearchCriteria($otherScope->scopeProfile, new PageRequest(), 'isolated-'));
        self::assertSame(1, $otherRegistry->total);
        self::assertSame(1, $otherRegistry->filtered);
        self::assertSame('isolated-other-slug', $otherRegistry->data[0]->slug->value);
    }

    private function engine(): SlugEngine
    {
        $profiles = new SlugProfileRegistry();
        $profiles->register(new TestSlugProfile());
        return SlugEngineFactory::create($this->pdo, $profiles, new TestReservedSlugPolicy(), $this->clock());
    }

    private function identity(string $entityKey): BindingIdentityDTO
    {
        return new BindingIdentityDTO(
            new ScopeProfileRequestDTO(new SlugScope('catalog', null, null), new SlugProfileKey('ascii-v1')),
            new EntityReference('product', $entityKey),
        );
    }

    private function identityInScope(string $namespace, ?string $locale, ?string $context, string $profile): BindingIdentityDTO
    {
        return new BindingIdentityDTO(
            new ScopeProfileRequestDTO(new SlugScope($namespace, $locale, $context), new SlugProfileKey($profile)),
            new EntityReference('product', $namespace . '-entity'),
        );
    }

    private function engineWithProfiles(): SlugEngine
    {
        $profiles = new SlugProfileRegistry();
        $profiles->register(new TestSlugProfile());
        $profiles->register(new TestSlugProfile(new SlugProfileKey('custom-v1')));
        return SlugEngineFactory::create($this->pdo, $profiles, new TestReservedSlugPolicy(), $this->clock());
    }
}
