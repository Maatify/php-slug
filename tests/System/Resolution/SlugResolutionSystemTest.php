<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\System\Resolution;

use Maatify\Slug\Command\AddAliasCommand;
use Maatify\Slug\Command\AssignExactCommand;
use Maatify\Slug\Command\ChangeExactCommand;
use Maatify\Slug\Command\DeactivateBindingCommand;
use Maatify\Slug\Criteria\ResolutionCriteria;
use Maatify\Slug\DTO\AuditContextDTO;
use Maatify\Slug\DTO\BindingIdentityDTO;
use Maatify\Slug\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Engine\SlugEngine;
use Maatify\Slug\Engine\SlugEngineFactory;
use Maatify\Slug\Enum\InputFormCanonicalityEnum;
use Maatify\Slug\Enum\MatchKindEnum;
use Maatify\Slug\Identity\EntityReference;
use Maatify\Slug\Identity\SlugProfileKey;
use Maatify\Slug\Profile\Registry\SlugProfileRegistry;
use Maatify\Slug\Scope\Value\SlugScope;
use Maatify\Slug\Tests\Integration\Schema\MySqlIntegrationTestCase;
use Maatify\Slug\Tests\Unit\Allocation\TestReservedSlugPolicy;
use Maatify\Slug\Tests\Unit\Allocation\TestSlugProfile;

final class SlugResolutionSystemTest extends MySqlIntegrationTestCase
{
    public function testResolutionCoversCurrentAliasHistoricalRetiredAndInactiveOwnership(): void
    {
        $engine = $this->engine();
        $identity = $this->identity('resolution-1');
        $engine->assignExact(new AssignExactCommand($identity, 'Current Slug', null, new AuditContextDTO()));
        $engine->addAlias(new AddAliasCommand($identity, 'active alias', 1, new AuditContextDTO()));
        $engine->changeExact(new ChangeExactCommand($identity, 'new current', 2, new AuditContextDTO()));
        $engine->retireAlias(new \Maatify\Slug\Command\RetireAliasCommand($identity, 'active alias', 3, new AuditContextDTO()));

        $current = $engine->resolve(new ResolutionCriteria($identity->scopeProfile, 'new-current'));
        self::assertSame(MatchKindEnum::CURRENT, $current->matchKind);
        self::assertSame(InputFormCanonicalityEnum::CANONICAL, $current->inputCanonicality);
        self::assertSame('ACTIVE', $current->bindingStatus?->value);

        $historical = $engine->resolve(new ResolutionCriteria($identity->scopeProfile, 'current-slug'));
        self::assertSame(MatchKindEnum::HISTORICAL, $historical->matchKind);
        self::assertSame('new-current', $historical->currentSlug?->value);

        $retired = $engine->resolve(new ResolutionCriteria($identity->scopeProfile, 'active-alias'));
        self::assertSame(MatchKindEnum::RETIRED_ALIAS, $retired->matchKind);

        $engine->deactivate(new DeactivateBindingCommand($identity, 4, new AuditContextDTO()));
        $inactive = $engine->resolve(new ResolutionCriteria($identity->scopeProfile, 'new-current'));
        self::assertSame('INACTIVE', $inactive->bindingStatus?->value);
    }

    public function testInvalidResolutionDoesNotBootstrapScopeOrBinding(): void
    {
        $engine = $this->engine();
        $scopeProfile = new ScopeProfileRequestDTO(new SlugScope('resolution-invalid', null, null), new SlugProfileKey('ascii-v1'));

        $result = $engine->resolve(new ResolutionCriteria($scopeProfile, "invalid\0segment"));

        self::assertSame(MatchKindEnum::NONE, $result->matchKind);
        self::assertSame(InputFormCanonicalityEnum::INVALID, $result->inputCanonicality);
        $statement = $this->pdo->query('SELECT COUNT(*) FROM maa_slug_scopes');
        self::assertNotFalse($statement);
        self::assertSame(0, (int) $statement->fetchColumn());
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
            new ScopeProfileRequestDTO(new SlugScope('resolution', null, null), new SlugProfileKey('ascii-v1')),
            new EntityReference('product', $entityKey),
        );
    }
}
