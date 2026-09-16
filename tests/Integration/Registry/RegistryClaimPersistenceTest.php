<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Integration\Registry;

use Maatify\Slug\Availability\SlugAvailabilityChecker;
use Maatify\Slug\Allocation\GeneratedCandidateSequence;
use Maatify\Slug\Contract\ReservedSlugPolicyInterface;
use Maatify\Slug\Criteria\AvailabilityCriteria;
use Maatify\Slug\DTO\BindingIdentityDTO;
use Maatify\Slug\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Enum\AvailabilityStatusEnum;
use Maatify\Slug\Exception\SlugAllocationExhaustedException;
use Maatify\Slug\Exception\SlugAlreadyClaimedException;
use Maatify\Slug\Exception\SlugReservedException;
use Maatify\Slug\Identity\EntityReference;
use Maatify\Slug\Identity\Slug;
use Maatify\Slug\Identity\SlugProfileKey;
use Maatify\Slug\Infrastructure\Persistence\PDO\Connection\PdoCapabilityGuard;
use Maatify\Slug\Infrastructure\Persistence\PDO\Registry\PdoRegistryRepository;
use Maatify\Slug\Infrastructure\Persistence\PDO\Scope\PdoScopeRepository;
use Maatify\Slug\Internal\Claim\RegistryClaimCoordinator;
use Maatify\Slug\Internal\Transaction\PdoTransactionCoordinator;
use Maatify\Slug\Profile\Registry\SlugProfileRegistry;
use Maatify\Slug\Scope\Value\SlugScope;
use Maatify\Slug\Tests\Integration\Schema\MySqlIntegrationTestCase;
use Maatify\Slug\Tests\Unit\Allocation\TestReservedSlugPolicy;
use Maatify\Slug\Tests\Unit\Allocation\TestSlugProfile;
use PDO;

final class RegistryClaimPersistenceTest extends MySqlIntegrationTestCase
{
    public function testExactClaimCreatesOneAtomicCurrentClaimAndPointer(): void
    {
        $coordinator = $this->coordinator($this->pdo);
        $identity = $this->identity('catalog', 'product-1');

        $result = $coordinator->claimExact($identity, 'Product-1');

        self::assertSame('product-1', $result->claim->slug->value);
        self::assertSame('CURRENT_CANONICAL', $result->claim->role->value);
        self::assertSame('ACTIVE', $result->binding->state->status->value);
        self::assertSame(1, $result->binding->state->revision);
        self::assertSame($result->claim->id, $result->binding->currentClaim?->id);
        self::assertSame(
            1,
            $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE scope_id = :scope_id', [
                'scope_id' => $this->scopeId('catalog'),
            ]),
        );
        self::assertSame(
            1,
            $this->scalarInt(
                'SELECT COUNT(*) FROM maa_slug_bindings WHERE entity_key = :entity_key AND status = :status AND revision = :revision',
                ['entity_key' => 'product-1', 'status' => 'ACTIVE', 'revision' => 1],
            ),
        );
    }

    public function testExactClaimHonorsReservationAndOtherBindingOwnership(): void
    {
        $reservedCoordinator = $this->coordinator($this->pdo, ['reserved']);
        $reservedIdentity = $this->identity('catalog', 'reserved-owner');
        try {
            $reservedCoordinator->claimExact($reservedIdentity, 'reserved');
            self::fail('A reserved exact candidate was claimed.');
        } catch (SlugReservedException) {
            self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'reserved-owner']));
            self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry'));
        }

        $owner = $reservedCoordinator->claimExact($this->identity('catalog', 'owner'), 'taken');
        try {
            $reservedCoordinator->claimExact($this->identity('catalog', 'other'), 'taken');
            self::fail('A second Binding claimed an owned slug.');
        } catch (SlugAlreadyClaimedException) {
            self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE slug = :slug', ['slug' => $owner->claim->slug->value]));
            self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_bindings WHERE entity_key = :entity_key AND status = :status', ['entity_key' => 'owner', 'status' => 'ACTIVE']));
        }
    }

    public function testGeneratedAllocationPreservesOrderAndCountsReservations(): void
    {
        $coordinator = $this->coordinator($this->pdo, ['product-2']);
        $coordinator->claimExact($this->identity('catalog', 'first'), 'product');

        $result = $coordinator->allocateGenerated($this->identity('catalog', 'second'), 'Product');

        self::assertSame('product-3', $result->claim->slug->value);
        self::assertSame(3, $result->attempts);
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry'));
    }

    public function testSameSlugCanBeOwnedIndependentlyInDifferentScopes(): void
    {
        $coordinator = $this->coordinator($this->pdo);

        $first = $coordinator->claimExact($this->identity('catalog', 'same-slug'), 'product');
        $second = $coordinator->claimExact($this->identity('store', 'same-slug'), 'product');

        self::assertSame('product', $first->claim->slug->value);
        self::assertSame('product', $second->claim->slug->value);
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE slug = :slug', ['slug' => 'product']));
    }

    public function testAvailabilityIsReadOnlyAndReturnsTheFiveAdvisoryClassifications(): void
    {
        $policy = new TestReservedSlugPolicy(['reserved']);
        $profiles = new SlugProfileRegistry();
        $profiles->register(new TestSlugProfile());
        $capabilities = new PdoCapabilityGuard($this->pdo);
        $scopes = new PdoScopeRepository($this->pdo, $profiles, $this->clock(), $capabilities);
        $repository = new PdoRegistryRepository($this->pdo, $profiles, $scopes, $this->clock(), $capabilities);
        $checker = new SlugAvailabilityChecker($repository, $profiles, $policy);
        $scopeProfile = $this->identity('catalog', 'owner')->scopeProfile;

        $available = $checker->check(new AvailabilityCriteria(
            new ScopeProfileRequestDTO(new SlugScope('new-scope', null, null), new SlugProfileKey('ascii-v1')),
            'free',
        ));
        self::assertSame(AvailabilityStatusEnum::AVAILABLE, $available->status);
        self::assertTrue($available->advisory);
        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_scopes WHERE namespace = :namespace', ['namespace' => 'new-scope']));

        $coordinator = new RegistryClaimCoordinator($repository, $profiles, $policy, new PdoTransactionCoordinator($this->pdo));
        $owner = $coordinator->claimExact($this->identity('catalog', 'owner'), 'taken');
        $same = $checker->check(new AvailabilityCriteria($scopeProfile, 'taken', $owner->binding->identity));
        $other = $checker->check(new AvailabilityCriteria($scopeProfile, 'taken'));
        $reserved = $checker->check(new AvailabilityCriteria($scopeProfile, 'reserved'));
        $invalid = $checker->check(new AvailabilityCriteria($scopeProfile, 'not valid'));

        self::assertSame(AvailabilityStatusEnum::OWNED_BY_SAME_BINDING, $same->status);
        self::assertSame(AvailabilityStatusEnum::OWNED_BY_OTHER_BINDING, $other->status);
        self::assertSame(AvailabilityStatusEnum::RESERVED, $reserved->status);
        self::assertSame(AvailabilityStatusEnum::INVALID, $invalid->status);
        self::assertNotNull($same->owner);
        self::assertSame($owner->binding->id, $same->owner->id);
        self::assertNull($reserved->owner);
    }

    public function testAllReservedGeneratedCandidatesExhaustAndRollbackBindingMutation(): void
    {
        $sequence = (new GeneratedCandidateSequence())->fromSource(new TestSlugProfile(), 'Product');
        $reserved = array_map(static fn(Slug $slug): string => $slug->value, $sequence);
        $coordinator = $this->coordinator($this->pdo, $reserved);

        try {
            $coordinator->allocateGenerated($this->identity('catalog', 'exhausted'), 'Product');
            self::fail('Reserved generated candidates did not exhaust.');
        } catch (SlugAllocationExhaustedException) {
            self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'exhausted']));
            self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry'));
        }
    }

    /** @param list<string> $reserved */
    private function coordinator(PDO $pdo, array $reserved = []): RegistryClaimCoordinator
    {
        $profiles = new SlugProfileRegistry();
        $profiles->register(new TestSlugProfile());
        $capabilities = new PdoCapabilityGuard($pdo);
        $scopes = new PdoScopeRepository($pdo, $profiles, $this->clock(), $capabilities);
        $repository = new PdoRegistryRepository($pdo, $profiles, $scopes, $this->clock(), $capabilities);

        return new RegistryClaimCoordinator(
            $repository,
            $profiles,
            new TestReservedSlugPolicy($reserved),
            new PdoTransactionCoordinator($pdo),
        );
    }

    private function identity(string $namespace, string $entityKey): BindingIdentityDTO
    {
        return new BindingIdentityDTO(
            new ScopeProfileRequestDTO(new SlugScope($namespace, null, null), new SlugProfileKey('ascii-v1')),
            new EntityReference('product', $entityKey),
        );
    }

    private function scopeId(string $namespace): int
    {
        return $this->scalarInt('SELECT id FROM maa_slug_scopes WHERE namespace = :namespace', ['namespace' => $namespace]);
    }

    /** @param array<string, int|string> $parameters */
    private function scalarInt(string $sql, array $parameters = []): int
    {
        $statement = $this->pdo->prepare($sql);
        self::assertNotFalse($statement);
        $statement->execute($parameters);
        return (int) $statement->fetchColumn();
    }
}
