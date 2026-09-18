<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Integration\Management;

use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Slug\Lifecycle\Command\AddAliasCommand;
use Maatify\Slug\Lifecycle\Command\AssignExactCommand;
use Maatify\Slug\Lifecycle\Command\ChangeExactCommand;
use Maatify\Slug\Lifecycle\Command\ReleaseAllOwnershipCommand;
use Maatify\Slug\Management\Criteria\AliasCriteria;
use Maatify\Slug\Management\Criteria\BindingCriteria;
use Maatify\Slug\Management\Criteria\BindingSearchCriteria;
use Maatify\Slug\Query\Criteria\CurrentSlugCriteria;
use Maatify\Slug\Management\Criteria\HistoryCriteria;
use Maatify\Slug\Management\Criteria\RegistryCriteria;
use Maatify\Slug\Management\Criteria\RegistrySearchCriteria;
use Maatify\Slug\Management\Criteria\ScopeCriteria;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Registry\DTO\BindingIdentityDTO;
use Maatify\Slug\Scope\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Engine\SlugEngine;
use Maatify\Slug\Engine\SlugEngineFactory;
use Maatify\Slug\Lifecycle\Enum\HistoryEventTypeEnum;
use Maatify\Slug\Registry\Enum\RegistryRoleEnum;
use Maatify\Slug\Exception\SlugScopeProfileMismatchException;
use Maatify\Slug\Registry\Value\EntityReference;
use Maatify\Slug\Profile\Value\SlugProfileKey;
use Maatify\Slug\Profile\Registry\SlugProfileRegistry;
use Maatify\Slug\Scope\Value\SlugScope;
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
}
