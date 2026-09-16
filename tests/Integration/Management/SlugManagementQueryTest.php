<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Integration\Management;

use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Slug\Command\AddAliasCommand;
use Maatify\Slug\Command\AssignExactCommand;
use Maatify\Slug\Command\ChangeExactCommand;
use Maatify\Slug\Criteria\AliasCriteria;
use Maatify\Slug\Criteria\BindingCriteria;
use Maatify\Slug\Criteria\BindingSearchCriteria;
use Maatify\Slug\Criteria\CurrentSlugCriteria;
use Maatify\Slug\Criteria\HistoryCriteria;
use Maatify\Slug\Criteria\RegistryCriteria;
use Maatify\Slug\Criteria\RegistrySearchCriteria;
use Maatify\Slug\Criteria\ScopeCriteria;
use Maatify\Slug\DTO\AuditContextDTO;
use Maatify\Slug\DTO\BindingIdentityDTO;
use Maatify\Slug\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Engine\SlugEngine;
use Maatify\Slug\Engine\SlugEngineFactory;
use Maatify\Slug\Enum\HistoryEventTypeEnum;
use Maatify\Slug\Enum\RegistryRoleEnum;
use Maatify\Slug\Identity\EntityReference;
use Maatify\Slug\Identity\SlugProfileKey;
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
