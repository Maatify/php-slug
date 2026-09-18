<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Integration\Registry;

use Maatify\Slug\Query\Availability\SlugAvailabilityChecker;
use Maatify\Slug\Lifecycle\Allocation\GeneratedCandidateSequence;
use Maatify\Slug\Lifecycle\Contract\ReservedSlugPolicyInterface;
use Maatify\Slug\Query\Criteria\AvailabilityCriteria;
use Maatify\Slug\Registry\DTO\BindingIdentityDTO;
use Maatify\Slug\Scope\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Query\Enum\AvailabilityStatusEnum;
use Maatify\Slug\Registry\Enum\RegistryRoleEnum;
use Maatify\Slug\Exception\SlugAllocationExhaustedException;
use Maatify\Slug\Exception\SlugAlreadyClaimedException;
use Maatify\Slug\Exception\SlugPersistenceInvariantException;
use Maatify\Slug\Exception\SlugRevisionConflictException;
use Maatify\Slug\Exception\SlugReservedException;
use Maatify\Slug\Registry\Value\EntityReference;
use Maatify\Slug\Text\Value\Slug;
use Maatify\Slug\Profile\Value\SlugProfileKey;
use Maatify\Slug\Persistence\PDO\Connection\PdoCapabilityGuard;
use Maatify\Slug\Persistence\PDO\Registry\PdoRegistryRepository;
use Maatify\Slug\Persistence\PDO\Scope\PdoScopeRepository;
use Maatify\Slug\Registry\Internal\Claim\RegistryClaimCoordinator;
use Maatify\Slug\Persistence\Transaction\PdoTransactionCoordinator;
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
            self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_scopes WHERE namespace = :namespace', ['namespace' => 'catalog']));
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
            self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'other']));
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
            self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_scopes WHERE namespace = :namespace', ['namespace' => 'catalog']));
            self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'exhausted']));
            self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry'));
        }
    }

    public function testSuccessfulClaimUsesHostOuterTransactionAndHostRollbackRemovesAllPackageState(): void
    {
        $identity = $this->identity('host-rollback', 'product-1');
        self::assertTrue($this->pdo->beginTransaction());

        $result = $this->coordinator($this->pdo)->claimExact($identity, 'product');

        self::assertSame('product', $result->claim->slug->value);
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_scopes WHERE namespace = :namespace', ['namespace' => 'host-rollback']));
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'product-1']));
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE slug = :slug', ['slug' => 'product']));
        self::assertTrue($this->pdo->inTransaction());

        self::assertTrue($this->pdo->rollBack());
        $observer = $this->newTestConnection();
        self::assertSame(0, $this->scalarIntOn($observer, 'SELECT COUNT(*) FROM maa_slug_scopes WHERE namespace = :namespace', ['namespace' => 'host-rollback']));
        self::assertSame(0, $this->scalarIntOn($observer, 'SELECT COUNT(*) FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'product-1']));
        self::assertSame(0, $this->scalarIntOn($observer, 'SELECT COUNT(*) FROM maa_slug_registry WHERE slug = :slug', ['slug' => 'product']));
    }

    public function testRevisionFailureAfterScopeAndBindingBootstrapRollsBackAllNewState(): void
    {
        $identity = $this->identity('revision-rollback', 'owner');

        try {
            $this->coordinator($this->pdo)->claimExact($identity, 'product', 1);
            self::fail('A missing Binding accepted an expected revision.');
        } catch (SlugRevisionConflictException) {
            self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_scopes WHERE namespace = :namespace', ['namespace' => 'revision-rollback']));
            self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_bindings WHERE entity_key = :entity_key', ['entity_key' => 'owner']));
            self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry'));
        }
    }

    public function testReleasedBindingWithCorruptPointerFailsBeforeMutation(): void
    {
        [$identity, $bindingId] = $this->prepareBindingFixture('corrupt-released-pointer', 'owner');
        $statement = $this->pdo->prepare('UPDATE maa_slug_bindings SET current_registry_id = :registry_id WHERE id = :binding_id');
        self::assertNotFalse($statement);
        $statement->execute(['registry_id' => 987654, 'binding_id' => $bindingId]);

        $this->expectInvariantFailure($identity);

        self::assertSame(987654, $this->scalarInt('SELECT current_registry_id FROM maa_slug_bindings WHERE id = :binding_id', ['binding_id' => $bindingId]));
        self::assertSame('RELEASED', $this->scalarString('SELECT status FROM maa_slug_bindings WHERE id = :binding_id', ['binding_id' => $bindingId]));
        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry'));
    }

    public function testReleasedBindingWithLiveRegistryClaimFailsBeforeMutation(): void
    {
        [$identity, $bindingId, $scopeId] = $this->prepareBindingFixture('corrupt-released-claim', 'owner');
        $claimId = $this->insertRegistryFixture($scopeId, $bindingId, 'released-live-claim');

        $this->expectInvariantFailure($identity);

        self::assertSame($claimId, $this->scalarInt('SELECT id FROM maa_slug_registry WHERE binding_id = :binding_id', ['binding_id' => $bindingId]));
        self::assertSame(0, $this->scalarInt('SELECT current_registry_id FROM maa_slug_bindings WHERE id = :binding_id', ['binding_id' => $bindingId]));
        self::assertSame('RELEASED', $this->scalarString('SELECT status FROM maa_slug_bindings WHERE id = :binding_id', ['binding_id' => $bindingId]));
    }

    public function testActiveAndInactiveBindingsWithoutPointersFailBeforeMutation(): void
    {
        $active = $this->coordinator($this->pdo)->claimExact($this->identity('corrupt-active-null', 'owner'), 'active-slug');
        $inactive = $this->coordinator($this->pdo)->claimExact($this->identity('corrupt-inactive-null', 'owner'), 'inactive-slug');
        $activeBindingId = $active->binding->id;
        $inactiveBindingId = $inactive->binding->id;
        $statement = $this->pdo->prepare('UPDATE maa_slug_bindings SET current_registry_id = NULL, status = :status WHERE id = :binding_id');
        self::assertNotFalse($statement);
        $statement->execute(['status' => 'ACTIVE', 'binding_id' => $activeBindingId]);
        $statement->execute(['status' => 'INACTIVE', 'binding_id' => $inactiveBindingId]);

        $this->expectInvariantFailure($this->identity('corrupt-active-null', 'owner'));
        $this->expectInvariantFailure($this->identity('corrupt-inactive-null', 'owner'));

        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry'));
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_bindings WHERE current_registry_id IS NULL AND status IN (\'ACTIVE\', \'INACTIVE\')'));
    }

    public function testPointerToNonCurrentRoleFailsBeforeMutation(): void
    {
        $result = $this->coordinator($this->pdo)->claimExact($this->identity('corrupt-role', 'owner'), 'role-slug');
        $statement = $this->pdo->prepare('UPDATE maa_slug_registry SET claim_role = :role WHERE id = :claim_id');
        self::assertNotFalse($statement);
        $statement->execute(['role' => RegistryRoleEnum::HISTORICAL_CANONICAL->value, 'claim_id' => $result->claim->id]);

        $this->expectInvariantFailure($this->identity('corrupt-role', 'owner'));

        self::assertSame(RegistryRoleEnum::HISTORICAL_CANONICAL->value, $this->scalarString('SELECT claim_role FROM maa_slug_registry WHERE id = :claim_id', ['claim_id' => $result->claim->id]));
        self::assertSame($result->claim->id, $this->scalarInt('SELECT current_registry_id FROM maa_slug_bindings WHERE id = :binding_id', ['binding_id' => $result->binding->id]));
    }

    public function testMultipleCurrentClaimsFailBeforeMutation(): void
    {
        $result = $this->coordinator($this->pdo)->claimExact($this->identity('corrupt-multiple-current', 'owner'), 'first-slug');
        $this->insertRegistryFixture($this->scopeId('corrupt-multiple-current'), $result->binding->id, 'second-slug');

        $this->expectInvariantFailure($this->identity('corrupt-multiple-current', 'owner'));

        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry WHERE binding_id = :binding_id', ['binding_id' => $result->binding->id]));
        self::assertSame($result->claim->id, $this->scalarInt('SELECT current_registry_id FROM maa_slug_bindings WHERE id = :binding_id', ['binding_id' => $result->binding->id]));
    }

    public function testPointerToAnotherBindingAndScopeFailsBeforeMutation(): void
    {
        $first = $this->coordinator($this->pdo)->claimExact($this->identity('corrupt-pointer-owner', 'first'), 'first-slug');
        $second = $this->coordinator($this->pdo)->claimExact($this->identity('corrupt-pointer-target', 'second'), 'second-slug');
        $statement = $this->pdo->prepare('UPDATE maa_slug_bindings SET current_registry_id = :registry_id WHERE id = :binding_id');
        self::assertNotFalse($statement);
        $statement->execute(['registry_id' => $second->claim->id, 'binding_id' => $first->binding->id]);

        $this->expectInvariantFailure($this->identity('corrupt-pointer-owner', 'first'));

        self::assertSame($second->claim->id, $this->scalarInt('SELECT current_registry_id FROM maa_slug_bindings WHERE id = :binding_id', ['binding_id' => $first->binding->id]));
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_registry'));
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM maa_slug_bindings WHERE status = :status', ['status' => 'ACTIVE']));
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

    /** @return array{0: BindingIdentityDTO, 1: int, 2: int} */
    private function prepareBindingFixture(string $namespace, string $entityKey): array
    {
        $identity = $this->identity($namespace, $entityKey);
        $profiles = new SlugProfileRegistry();
        $profiles->register(new TestSlugProfile());
        $capabilities = new PdoCapabilityGuard($this->pdo);
        $repository = new PdoScopeRepository($this->pdo, $profiles, $this->clock(), $capabilities);
        $binding = $repository->ensureBindingPlaceholder($identity->scopeProfile, $identity->entity);

        return [$identity, $binding->id, $this->scopeId($namespace)];
    }

    private function insertRegistryFixture(int $scopeId, int $bindingId, string $slug): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO maa_slug_registry '
            . '(scope_id, binding_id, slug, claim_role, claimed_at, updated_at) '
            . 'VALUES (:scope_id, :binding_id, :slug, :claim_role, :claimed_at, :updated_at)',
        );
        self::assertNotFalse($statement);
        $timestamp = $this->clock()->now()->format('Y-m-d H:i:s.u');
        $statement->execute([
            'scope_id' => $scopeId,
            'binding_id' => $bindingId,
            'slug' => $slug,
            'claim_role' => RegistryRoleEnum::CURRENT_CANONICAL->value,
            'claimed_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function expectInvariantFailure(BindingIdentityDTO $identity): void
    {
        try {
            $this->coordinator($this->pdo)->claimExact($identity, 'candidate', 0);
            self::fail('Corrupt Binding state was silently accepted or repaired.');
        } catch (SlugPersistenceInvariantException) {
            return;
        }
    }

    /** @param array<string, int|string> $parameters */
    private function scalarInt(string $sql, array $parameters = []): int
    {
        return $this->scalarIntOn($this->pdo, $sql, $parameters);
    }

    /** @param array<string, int|string> $parameters */
    private function scalarIntOn(PDO $pdo, string $sql, array $parameters = []): int
    {
        $statement = $pdo->prepare($sql);
        self::assertNotFalse($statement);
        $statement->execute($parameters);
        return (int) $statement->fetchColumn();
    }

    /** @param array<string, int|string> $parameters */
    private function scalarString(string $sql, array $parameters = []): string
    {
        $statement = $this->pdo->prepare($sql);
        self::assertNotFalse($statement);
        $statement->execute($parameters);
        return (string) $statement->fetchColumn();
    }
}
