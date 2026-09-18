<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\System\Resolution;

use Maatify\Slug\Lifecycle\Command\AddAliasCommand;
use Maatify\Slug\Lifecycle\Command\AssignExactCommand;
use Maatify\Slug\Lifecycle\Command\ChangeExactCommand;
use Maatify\Slug\Lifecycle\Command\DeactivateBindingCommand;
use Maatify\Slug\Lifecycle\Command\ReleaseAllOwnershipCommand;
use Maatify\Slug\Query\Criteria\ResolutionCriteria;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Registry\DTO\BindingIdentityDTO;
use Maatify\Slug\Scope\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Engine\SlugEngine;
use Maatify\Slug\Engine\SlugEngineFactory;
use Maatify\Slug\Query\Enum\InputFormCanonicalityEnum;
use Maatify\Slug\Query\Enum\MatchKindEnum;
use Maatify\Slug\Exception\SlugScopeProfileMismatchException;
use Maatify\Slug\Registry\Value\EntityReference;
use Maatify\Slug\Profile\Value\SlugProfileKey;
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
        $engine->assignExact(new AssignExactCommand($identity, 'current-slug', null, new AuditContextDTO()));
        $engine->addAlias(new AddAliasCommand($identity, 'active-alias', 1, new AuditContextDTO()));
        $engine->changeExact(new ChangeExactCommand($identity, 'new-current', 2, new AuditContextDTO()));
        $activeAlias = $engine->resolve(new ResolutionCriteria($identity->scopeProfile, 'active-alias'));
        self::assertSame(MatchKindEnum::ALIAS, $activeAlias->matchKind);
        self::assertSame(InputFormCanonicalityEnum::CANONICAL, $activeAlias->inputCanonicality);
        $engine->retireAlias(new \Maatify\Slug\Lifecycle\Command\RetireAliasCommand($identity, 'active-alias', 3, new AuditContextDTO()));

        $current = $engine->resolve(new ResolutionCriteria($identity->scopeProfile, 'new-current'));
        self::assertSame(MatchKindEnum::CURRENT, $current->matchKind);
        self::assertSame(InputFormCanonicalityEnum::CANONICAL, $current->inputCanonicality);
        self::assertSame('ACTIVE', $current->bindingStatus?->value);

        $nonCanonical = $engine->resolve(new ResolutionCriteria($identity->scopeProfile, 'NEW-CURRENT'));
        self::assertSame(MatchKindEnum::CURRENT, $nonCanonical->matchKind);
        self::assertSame(InputFormCanonicalityEnum::NON_CANONICAL, $nonCanonical->inputCanonicality);

        $historical = $engine->resolve(new ResolutionCriteria($identity->scopeProfile, 'current-slug'));
        self::assertSame(MatchKindEnum::HISTORICAL, $historical->matchKind);
        self::assertSame('new-current', $historical->currentSlug?->value);

        $retired = $engine->resolve(new ResolutionCriteria($identity->scopeProfile, 'active-alias'));
        self::assertSame(MatchKindEnum::RETIRED_ALIAS, $retired->matchKind);

        $engine->deactivate(new DeactivateBindingCommand($identity, 4, new AuditContextDTO()));
        $inactive = $engine->resolve(new ResolutionCriteria($identity->scopeProfile, 'new-current'));
        self::assertSame('INACTIVE', $inactive->bindingStatus?->value);
    }

    public function testReleasedClaimsDoNotResolveAndReuseResolvesOnlyNewOwner(): void
    {
        $engine = $this->engine();
        $old = $this->identity('resolution-released-old');
        $engine->assignExact(new AssignExactCommand($old, 'reusable-resolution-slug', null, new AuditContextDTO()));
        $engine->releaseAllOwnership(new ReleaseAllOwnershipCommand($old, 1, new AuditContextDTO()));

        $released = $engine->resolve(new ResolutionCriteria($old->scopeProfile, 'reusable-resolution-slug'));
        self::assertSame(MatchKindEnum::NONE, $released->matchKind);
        self::assertSame(InputFormCanonicalityEnum::NOT_APPLICABLE, $released->inputCanonicality);
        self::assertNull($released->matchedSlug);

        $new = $this->identity('resolution-released-new');
        $engine->assignExact(new AssignExactCommand($new, 'reusable-resolution-slug', null, new AuditContextDTO()));
        $resolved = $engine->resolve(new ResolutionCriteria($old->scopeProfile, 'reusable-resolution-slug'));
        self::assertSame(MatchKindEnum::CURRENT, $resolved->matchKind);
        self::assertSame('resolution-released-new', $resolved->entity?->entityKey);
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

        $beforeScopes = $this->scalarInt('SELECT COUNT(*) FROM maa_slug_scopes');
        $beforeClaims = $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry');
        $noMatchScope = new ScopeProfileRequestDTO(new SlugScope('resolution-no-match', null, null), new SlugProfileKey('ascii-v1'));
        $noMatch = $engine->resolve(new ResolutionCriteria($noMatchScope, 'valid-no-match'));
        self::assertSame(MatchKindEnum::NONE, $noMatch->matchKind);
        self::assertSame(InputFormCanonicalityEnum::NOT_APPLICABLE, $noMatch->inputCanonicality);
        self::assertSame($beforeScopes, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_scopes'));
        self::assertSame($beforeClaims, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry'));
    }

    public function testResolutionRejectsProfileMismatchBeforeLookup(): void
    {
        $profiles = new SlugProfileRegistry();
        $profiles->register(new TestSlugProfile());
        $profiles->register(new TestSlugProfile(new SlugProfileKey('other-v1')));
        $engine = SlugEngineFactory::create($this->pdo, $profiles, new TestReservedSlugPolicy(), $this->clock());
        $identity = $this->identity('resolution-profile-mismatch');
        $engine->assignExact(new AssignExactCommand($identity, 'profile-slug', null, new AuditContextDTO()));

        $wrongProfile = new ScopeProfileRequestDTO($identity->scopeProfile->scope, new SlugProfileKey('other-v1'));
        $this->expectException(SlugScopeProfileMismatchException::class);
        $engine->resolve(new ResolutionCriteria($wrongProfile, 'profile-slug'));
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

    private function scalarInt(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement);
        return (int) $statement->fetchColumn();
    }
}
