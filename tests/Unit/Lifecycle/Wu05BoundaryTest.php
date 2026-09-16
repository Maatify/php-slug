<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Lifecycle;

use Maatify\Slug\Contract\SlugLifecycleServiceInterface;
use Maatify\Slug\Command\AtomicTransferCommand;
use Maatify\Slug\DTO\AuditContextDTO;
use Maatify\Slug\DTO\AtomicTransferResultDTO;
use Maatify\Slug\DTO\BindingStateResultDTO;
use Maatify\Slug\DTO\HistoryEventDTO;
use Maatify\Slug\DTO\SlugMutationResultDTO;
use Maatify\Slug\Enum\BindingStatusEnum;
use Maatify\Slug\Enum\ChangeTypeEnum;
use Maatify\Slug\Enum\OperationTypeEnum;
use Maatify\Slug\Enum\HistoryEventTypeEnum;
use Maatify\Slug\Enum\RegistryRoleEnum;
use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\History\HistoryEventDraft;
use Maatify\Slug\Identity\EntityReference;
use Maatify\Slug\Identity\Slug;
use Maatify\Slug\Identity\SlugProfileKey;
use Maatify\Slug\Ownership\SameBindingDecisionEnum;
use Maatify\Slug\Ownership\SameBindingOwnershipClassifier;
use Maatify\Slug\Profile\Registry\SlugProfileRegistry;
use Maatify\Slug\Scope\Value\SlugScope;
use Maatify\Slug\Tests\Unit\Allocation\TestSlugProfile;
use Maatify\Slug\Tests\Unit\Contracts\ContractFixtures;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class Wu05BoundaryTest extends TestCase
{
    public function testConcreteServiceExposesOnlyTheWu05OperationSet(): void
    {
        $reflection = new ReflectionClass(\Maatify\Slug\Lifecycle\SlugLifecycleService::class);
        $publicMethods = array_values(array_map(
            static fn(\ReflectionMethod $method): string => $method->getName(),
            array_filter(
                $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
                static fn(\ReflectionMethod $method): bool => $method->getName() !== '__construct'
                    && $method->getDeclaringClass()->getName() === $reflection->getName(),
            ),
        ));
        sort($publicMethods);

        $expected = [
            'addAlias',
            'assignExact',
            'assignGenerated',
            'atomicTransfer',
            'changeExact',
            'changeGenerated',
            'deactivate',
            'promoteAliasToCurrent',
            'purgeBinding',
            'reactivate',
            'reactivateAlias',
            'releaseAllOwnership',
            'releaseClaim',
            'restoreHistorical',
            'retireAlias',
        ];
        sort($expected);

        self::assertSame($expected, $publicMethods);
        self::assertFalse($reflection->implementsInterface(SlugLifecycleServiceInterface::class));
        self::assertNotContains('transitionScope', $publicMethods);
        self::assertNotContains('adoptCurrent', $publicMethods);
        self::assertNotContains('adoptHistorical', $publicMethods);
        self::assertNotContains('adoptAlias', $publicMethods);
    }

    public function testHistoryDraftCanRepresentAnImmutableClaimSnapshot(): void
    {
        $profile = new SlugProfileRegistry();
        $profile->register(new TestSlugProfile());
        $slugProfile = $profile->get(new SlugProfileKey('ascii-v1'));
        $scope = new SlugScope('unit', null, null);
        $entity = new EntityReference('product', '1');
        $slug = Slug::fromProfile($slugProfile, 'current');
        $draft = new HistoryEventDraft(
            10,
            4,
            HistoryEventTypeEnum::CHANGED,
            $scope,
            $entity,
            $slug,
            Slug::fromProfile($slugProfile, 'previous'),
            RegistryRoleEnum::CURRENT_CANONICAL,
            RegistryRoleEnum::HISTORICAL_CANONICAL,
            null,
            null,
            null,
            null,
            new AuditContextDTO(actorKey: 'unit-test'),
            new DateTimeImmutable('2026-01-01T00:00:00.123456Z'),
        );

        self::assertNotNull($draft->slugSnapshot);
        self::assertSame('current', $draft->slugSnapshot->value);
        self::assertSame('previous', $draft->previousSlugSnapshot?->value);
        self::assertSame(RegistryRoleEnum::HISTORICAL_CANONICAL, $draft->previousClaimRoleSnapshot);
    }

    public function testHistoryClaimSnapshotsMustKeepPreviousValuesPaired(): void
    {
        $profile = new TestSlugProfile();

        $this->expectException(SlugInvalidArgumentException::class);
        new HistoryEventDTO(
            1,
            10,
            1,
            HistoryEventTypeEnum::ASSIGNED,
            new SlugScope('unit', null, null),
            new EntityReference('product', '1'),
            Slug::fromProfile($profile, 'current'),
            Slug::fromProfile($profile, 'previous'),
            RegistryRoleEnum::CURRENT_CANONICAL,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            new DateTimeImmutable('2026-01-01T00:00:00.123456Z'),
            null,
        );
    }

    public function testWu05SameBindingDecisionMatrixCoversAssignChangeRestoreAndAliasPaths(): void
    {
        $classifier = new SameBindingOwnershipClassifier();
        $roles = [
            RegistryRoleEnum::CURRENT_CANONICAL,
            RegistryRoleEnum::HISTORICAL_CANONICAL,
            RegistryRoleEnum::ACTIVE_ALIAS,
            RegistryRoleEnum::RETIRED_ALIAS,
        ];
        $assignmentExpected = array_fill(0, count($roles), SameBindingDecisionEnum::REJECT_ASSIGNMENT);
        foreach ([OperationTypeEnum::ASSIGN_EXACT, OperationTypeEnum::ASSIGN_GENERATED] as $operation) {
            foreach ($roles as $index => $role) {
                self::assertSame($assignmentExpected[$index], $classifier->classify($operation, $role));
            }
        }

        foreach ([OperationTypeEnum::CHANGE_EXACT, OperationTypeEnum::CHANGE_GENERATED] as $operation) {
            self::assertSame(SameBindingDecisionEnum::NATURAL_NO_OP, $classifier->classify($operation, RegistryRoleEnum::CURRENT_CANONICAL));
            self::assertSame(SameBindingDecisionEnum::RESTORE_HISTORICAL, $classifier->classify($operation, RegistryRoleEnum::HISTORICAL_CANONICAL));
            self::assertSame(SameBindingDecisionEnum::REJECT_ALIAS_OPERATION, $classifier->classify($operation, RegistryRoleEnum::ACTIVE_ALIAS));
            self::assertSame(SameBindingDecisionEnum::REJECT_ALIAS_OPERATION, $classifier->classify($operation, RegistryRoleEnum::RETIRED_ALIAS));
        }

        self::assertSame(SameBindingDecisionEnum::RESTORE_HISTORICAL, $classifier->classify(OperationTypeEnum::RESTORE_HISTORICAL, RegistryRoleEnum::HISTORICAL_CANONICAL));
        foreach ([RegistryRoleEnum::CURRENT_CANONICAL, RegistryRoleEnum::ACTIVE_ALIAS, RegistryRoleEnum::RETIRED_ALIAS] as $role) {
            self::assertSame(SameBindingDecisionEnum::REJECT_HISTORICAL_RESTORE, $classifier->classify(OperationTypeEnum::RESTORE_HISTORICAL, $role));
        }

        self::assertSame(SameBindingDecisionEnum::ADD_ALIAS, $classifier->classify(OperationTypeEnum::ADD_ALIAS, RegistryRoleEnum::HISTORICAL_CANONICAL));
        self::assertSame(SameBindingDecisionEnum::NATURAL_NO_OP, $classifier->classify(OperationTypeEnum::ADD_ALIAS, RegistryRoleEnum::ACTIVE_ALIAS));
        foreach ([RegistryRoleEnum::CURRENT_CANONICAL, RegistryRoleEnum::RETIRED_ALIAS] as $role) {
            self::assertSame(SameBindingDecisionEnum::REJECT_ALIAS_OPERATION, $classifier->classify(OperationTypeEnum::ADD_ALIAS, $role));
        }

        self::assertSame(SameBindingDecisionEnum::RETIRE_ALIAS, $classifier->classify(OperationTypeEnum::RETIRE_ALIAS, RegistryRoleEnum::ACTIVE_ALIAS));
        self::assertSame(SameBindingDecisionEnum::REACTIVATE_ALIAS, $classifier->classify(OperationTypeEnum::REACTIVATE_ALIAS, RegistryRoleEnum::RETIRED_ALIAS));
        self::assertSame(SameBindingDecisionEnum::PROMOTE_ALIAS, $classifier->classify(OperationTypeEnum::PROMOTE_ALIAS, RegistryRoleEnum::ACTIVE_ALIAS));
    }

    public function testHistoryEventApplicabilityMatrixAcceptsMarkersClaimsPreviousSnapshotsAndAdoptionTimestamps(): void
    {
        $profile = new TestSlugProfile();
        $scope = new SlugScope('unit', null, null);
        $entity = new EntityReference('product', '1');
        $slug = Slug::fromProfile($profile, 'current');
        $previous = Slug::fromProfile($profile, 'previous');
        $accepted = 0;

        foreach ([HistoryEventTypeEnum::DEACTIVATED, HistoryEventTypeEnum::REACTIVATED, HistoryEventTypeEnum::OWNERSHIP_RELEASED_ALL] as $eventType) {
            new HistoryEventDTO(1, 10, ++$accepted, $eventType, $scope, $entity, null, null, null, null, null, null, null, null, null, null, new DateTimeImmutable('2026-01-01T00:00:00.123456Z'), null);
        }
        foreach ([HistoryEventTypeEnum::ASSIGNED, HistoryEventTypeEnum::ALIAS_ADDED, HistoryEventTypeEnum::ALIAS_RETIRED, HistoryEventTypeEnum::ALIAS_REACTIVATED, HistoryEventTypeEnum::OWNERSHIP_RELEASED, HistoryEventTypeEnum::OWNERSHIP_TRANSFERRED_OUT, HistoryEventTypeEnum::OWNERSHIP_TRANSFERRED_IN] as $eventType) {
            new HistoryEventDTO(1, 10, ++$accepted, $eventType, $scope, $entity, $slug, null, RegistryRoleEnum::ACTIVE_ALIAS, null, null, null, null, null, null, null, new DateTimeImmutable('2026-01-01T00:00:00.123456Z'), null);
        }
        foreach ([HistoryEventTypeEnum::CHANGED, HistoryEventTypeEnum::RESTORED, HistoryEventTypeEnum::ALIAS_PROMOTED] as $eventType) {
            new HistoryEventDTO(1, 10, ++$accepted, $eventType, $scope, $entity, $slug, $previous, RegistryRoleEnum::CURRENT_CANONICAL, RegistryRoleEnum::HISTORICAL_CANONICAL, null, null, null, null, null, null, new DateTimeImmutable('2026-01-01T00:00:00.123456Z'), null);
        }
        foreach ([HistoryEventTypeEnum::ADOPTED_CURRENT, HistoryEventTypeEnum::ADOPTED_HISTORICAL, HistoryEventTypeEnum::ADOPTED_ALIAS] as $eventType) {
            new HistoryEventDTO(1, 10, ++$accepted, $eventType, $scope, $entity, $slug, null, RegistryRoleEnum::CURRENT_CANONICAL, null, null, null, null, null, null, null, new DateTimeImmutable('2026-01-01T00:00:00.123456Z'), new DateTimeImmutable('2025-12-31T23:59:59.123456Z'));
        }

        self::assertSame(16, $accepted);
    }

    public function testFingerprintV1UsesTheCanonicalPayloadContract(): void
    {
        $reflection = new ReflectionClass(\Maatify\Slug\Lifecycle\SlugLifecycleService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('fingerprint');
        $identity = new \Maatify\Slug\DTO\BindingIdentityDTO(
            new \Maatify\Slug\DTO\ScopeProfileRequestDTO(new SlugScope('catalog', null, null), new SlugProfileKey('ascii-v1')),
            new EntityReference('product', '1'),
        );
        $actual = $method->invoke(
            $service,
            OperationTypeEnum::ASSIGN_EXACT,
            $identity,
            ['mode' => 'EXACT', 'value' => 'hello'],
            ['mode' => 'EXACT', 'value' => 'replacement'],
            null,
            7,
            8,
        );
        $payload = [
            'contract_version' => 1,
            'operation_type' => 'ASSIGN_EXACT',
            'source_scope' => ['namespace' => 'catalog', 'locale_key' => null, 'context_key' => null, 'profile_key' => 'ascii-v1'],
            'source_binding' => ['entity_type' => 'product', 'entity_key' => '1'],
            'target_scope' => null,
            'target_binding' => null,
            'claim_intent' => ['mode' => 'EXACT', 'value' => 'hello'],
            'source_replacement_intent' => ['mode' => 'EXACT', 'value' => 'replacement'],
            'transition_mode' => null,
            'expected_revision' => null,
            'source_expected_revision' => 7,
            'target_expected_revision' => 8,
            'original_occurred_at' => null,
        ];
        self::assertSame(hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)), $actual);
    }

    public function testAtomicTransferCommandRejectsCrossScopeInputs(): void
    {
        $source = $this->transferIdentity('source', 'catalog');
        $target = $this->transferIdentity('target', 'store');

        $this->expectException(SlugInvalidArgumentException::class);
        new AtomicTransferCommand($source, $target, 'slug', null, 1, 1, new AuditContextDTO());
    }

    public function testAtomicTransferCommandRejectsTheSameBinding(): void
    {
        $identity = $this->transferIdentity('same', 'catalog');

        $this->expectException(SlugInvalidArgumentException::class);
        new AtomicTransferCommand($identity, $identity, 'slug', null, 1, 1, new AuditContextDTO());
    }

    public function testTransferResultMatrixPreservesCurrentChangedRestoredAndAllNonCurrentRoles(): void
    {
        $changed = ContractFixtures::transfer();
        self::assertSame(RegistryRoleEnum::CURRENT_CANONICAL, $changed->transferredClaim->role);
        self::assertSame(ChangeTypeEnum::CHANGED, $changed->sourceReplacementResult?->changeType);
        self::assertSame(BindingStatusEnum::RELEASED, $changed->targetResult->before?->state->status);

        $restored = $this->restoredTransfer($changed);
        self::assertSame(RegistryRoleEnum::CURRENT_CANONICAL, $restored->transferredClaim->role);
        self::assertSame(ChangeTypeEnum::RESTORED, $restored->sourceReplacementResult?->changeType);

        foreach ([RegistryRoleEnum::HISTORICAL_CANONICAL, RegistryRoleEnum::ACTIVE_ALIAS, RegistryRoleEnum::RETIRED_ALIAS] as $role) {
            $nonCurrent = $this->nonCurrentTransfer($role);
            self::assertSame($role, $nonCurrent->transferredClaim->role);
            self::assertNull($nonCurrent->sourceReplacementResult);
            self::assertSame(BindingStatusEnum::ACTIVE, $nonCurrent->targetResult->before?->state->status);
        }
    }

    private function restoredTransfer(AtomicTransferResultDTO $changed): AtomicTransferResultDTO
    {
        $sourceBefore = $changed->sourceResult->before;
        $sourceAfter = $changed->sourceResult->after;
        $targetResult = $changed->targetResult;
        if ($sourceBefore === null || $changed->sourceReplacementResult === null || $sourceAfter->currentClaim === null) {
            throw new \LogicException('Changed transfer fixture is incomplete.');
        }
        $moved = $changed->sourceReplacementResult->previousSlug;
        $replacement = $sourceAfter->currentClaim->slug;
        if ($moved === null) {
            throw new \LogicException('Changed transfer fixture has no moved slug.');
        }
        $restoredEvent = ContractFixtures::history(
            $sourceAfter->id,
            506,
            2,
            $sourceAfter->identity,
            HistoryEventTypeEnum::RESTORED,
            $replacement,
            RegistryRoleEnum::CURRENT_CANONICAL,
            $moved,
            RegistryRoleEnum::CURRENT_CANONICAL,
        );
        $sourceTransferEvent = $changed->sourceResult->historyEvents[1];
        $targetTransferEvent = $targetResult->historyEvents[0];
        $replacementResult = new SlugMutationResultDTO(
            OperationTypeEnum::ATOMIC_TRANSFER,
            null,
            false,
            $sourceBefore,
            $sourceAfter,
            [$sourceAfter->currentClaim],
            $moved,
            $replacement,
            ChangeTypeEnum::RESTORED,
            2,
            [$restoredEvent],
        );

        return new AtomicTransferResultDTO(
            OperationTypeEnum::ATOMIC_TRANSFER,
            null,
            false,
            new BindingStateResultDTO($sourceBefore, $sourceAfter, true, 2, [$restoredEvent, $sourceTransferEvent]),
            $targetResult,
            $changed->transferredClaim,
            $replacementResult,
            2,
            1,
            [$restoredEvent, $sourceTransferEvent, $targetTransferEvent],
        );
    }

    private function nonCurrentTransfer(RegistryRoleEnum $role): AtomicTransferResultDTO
    {
        $source = ContractFixtures::identity(10, 'transfer-unit');
        $target = ContractFixtures::identity(20, 'transfer-unit');
        $sourceBefore = ContractFixtures::binding($source, 100, 'source-current', 200, BindingStatusEnum::ACTIVE, 1);
        $sourceAfter = ContractFixtures::binding($source, 100, 'source-current', 200, BindingStatusEnum::ACTIVE, 2);
        $targetBefore = ContractFixtures::binding($target, 300, 'target-current', 201, BindingStatusEnum::ACTIVE, 1);
        $targetAfter = ContractFixtures::binding($target, 300, 'target-current', 201, BindingStatusEnum::ACTIVE, 2);
        $moved = ContractFixtures::slug('moved');
        $transferred = ContractFixtures::claim($target, $moved, 999, $role);
        $sourceEvent = ContractFixtures::history(100, 501, 2, $source, HistoryEventTypeEnum::OWNERSHIP_TRANSFERRED_OUT, $moved, $role);
        $targetEvent = ContractFixtures::history(300, 502, 2, $target, HistoryEventTypeEnum::OWNERSHIP_TRANSFERRED_IN, $moved, $role);

        return new AtomicTransferResultDTO(
            OperationTypeEnum::ATOMIC_TRANSFER,
            null,
            false,
            new BindingStateResultDTO($sourceBefore, $sourceAfter, true, 2, [$sourceEvent]),
            new BindingStateResultDTO($targetBefore, $targetAfter, true, 2, [$targetEvent]),
            $transferred,
            null,
            2,
            2,
            [$sourceEvent, $targetEvent],
        );
    }

    private function transferIdentity(string $entityKey, string $namespace): \Maatify\Slug\DTO\BindingIdentityDTO
    {
        return new \Maatify\Slug\DTO\BindingIdentityDTO(
            new \Maatify\Slug\DTO\ScopeProfileRequestDTO(new SlugScope($namespace, null, null), new SlugProfileKey('ascii-v1')),
            new EntityReference('product', $entityKey),
        );
    }
}
