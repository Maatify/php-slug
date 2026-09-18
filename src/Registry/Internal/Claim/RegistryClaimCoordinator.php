<?php

declare(strict_types=1);

namespace Maatify\Slug\Registry\Internal\Claim;

use PDOException;
use Maatify\Slug\Lifecycle\Contract\ReservedSlugPolicyInterface;
use Maatify\Slug\Lifecycle\Allocation\GeneratedCandidateSequence;
use Maatify\Slug\Registry\DTO\BindingIdentityDTO;
use Maatify\Slug\Registry\Enum\BindingStatusEnum;
use Maatify\Slug\Lifecycle\Enum\OperationTypeEnum;
use Maatify\Slug\Registry\Enum\RegistryRoleEnum;
use Maatify\Slug\Exception\SlugAlreadyClaimedException;
use Maatify\Slug\Exception\SlugAllocationExhaustedException;
use Maatify\Slug\Exception\SlugAssignmentNotPermittedException;
use Maatify\Slug\Exception\SlugPersistenceInvariantException;
use Maatify\Slug\Exception\SlugReservedException;
use Maatify\Slug\Exception\SlugRevisionConflictException;
use Maatify\Slug\Text\Value\Slug;
use Maatify\Slug\Persistence\PDO\Connection\PdoDuplicateClassifier;
use Maatify\Slug\Persistence\PDO\Registry\PdoRegistryRepository;
use Maatify\Slug\Persistence\PDO\Registry\RegistryBindingRecord;
use Maatify\Slug\Persistence\PDO\Registry\RegistryClaimRecord;
use Maatify\Slug\Persistence\Transaction\PdoTransactionCoordinator;
use Maatify\Slug\Lifecycle\Ownership\SameBindingDecisionEnum;
use Maatify\Slug\Lifecycle\Ownership\SameBindingOwnershipClassifier;
use Maatify\Slug\Profile\Contract\SlugProfileRegistryInterface;
use Maatify\Slug\Lifecycle\Reserved\ReservationEvaluator;

/**
 * Internal exact/generated ownership primitive. It intentionally does not
 * implement SlugLifecycleServiceInterface or expose public lifecycle API.
 */
final readonly class RegistryClaimCoordinator
{
    private ReservationEvaluator $reservations;

    public function __construct(
        private PdoRegistryRepository $registry,
        private SlugProfileRegistryInterface $profiles,
        ReservedSlugPolicyInterface $reservedPolicy,
        private PdoTransactionCoordinator $transactions,
        private SameBindingOwnershipClassifier $sameBinding = new SameBindingOwnershipClassifier(),
        private GeneratedCandidateSequence $candidates = new GeneratedCandidateSequence(),
    ) {
        $this->reservations = new ReservationEvaluator($reservedPolicy);
    }

    public function claimExact(
        BindingIdentityDTO $binding,
        string $slugCandidate,
        ?int $expectedRevision = null,
    ): OwnershipClaimResult {
        $profile = $this->profiles->get($binding->scopeProfile->expectedProfileKey);
        $slug = $profile->canonicalizeClaim($slugCandidate)->slug;
        $this->registry->assertInstalledSchemaSupported();

        return $this->transactions->run(function () use ($binding, $slug, $expectedRevision): OwnershipClaimResult {
            $scope = $this->registry->ensureScope(
                $binding->scopeProfile->scope,
                $binding->scopeProfile->expectedProfileKey,
            );

            return $this->claimExactInsideTransaction($binding, $scope, $slug, $expectedRevision);
        });
    }

    public function allocateGenerated(
        BindingIdentityDTO $binding,
        string $sourceText,
        ?int $expectedRevision = null,
    ): OwnershipClaimResult {
        $profile = $this->profiles->get($binding->scopeProfile->expectedProfileKey);
        $candidates = $this->candidates->fromSource($profile, $sourceText);
        $this->registry->assertInstalledSchemaSupported();

        return $this->transactions->run(function () use ($binding, $candidates, $expectedRevision): OwnershipClaimResult {
            $scope = $this->registry->ensureScope(
                $binding->scopeProfile->scope,
                $binding->scopeProfile->expectedProfileKey,
            );

            return $this->allocateInsideTransaction($binding, $scope, $candidates, $expectedRevision);
        });
    }

    private function claimExactInsideTransaction(
        BindingIdentityDTO $identity,
        \Maatify\Slug\Scope\DTO\ScopeDTO $scope,
        Slug $slug,
        ?int $expectedRevision,
    ): OwnershipClaimResult {
        $lockedScope = $this->registry->lockScope($scope->scope, $scope->profileKey);
        $binding = $this->registry->lockOrCreateBinding($lockedScope, $identity->entity);
        $this->assertAssignmentState($binding, $expectedRevision);

        $existing = $this->registry->findClaimByScopeSlug($lockedScope->id, $slug, true);
        if ($existing !== null) {
            $this->classifyExistingForAssignment($existing, $binding->id);
        }
        if ($this->reservations->isReserved($lockedScope->scope, $slug)) {
            throw new SlugReservedException('The requested slug is reserved.');
        }

        $claim = $this->insertForAssignment($lockedScope->id, $binding->id, $slug);
        $this->registry->activateCurrent($binding->id, $binding->revision, $claim->id);

        return $this->result($identity, $claim, 1);
    }

    /** @param list<Slug> $candidates */
    private function allocateInsideTransaction(
        BindingIdentityDTO $identity,
        \Maatify\Slug\Scope\DTO\ScopeDTO $scope,
        array $candidates,
        ?int $expectedRevision,
    ): OwnershipClaimResult {
        $lockedScope = $this->registry->lockScope($scope->scope, $scope->profileKey);
        $binding = $this->registry->lockOrCreateBinding($lockedScope, $identity->entity);
        $this->assertAssignmentState($binding, $expectedRevision);

        foreach ($candidates as $attempt => $slug) {
            $existing = $this->registry->findClaimByScopeSlug($lockedScope->id, $slug, true);
            if ($existing !== null) {
                $this->classifyExistingForGeneratedAssignment($existing, $binding->id);
                continue;
            }
            if ($this->reservations->isReserved($lockedScope->scope, $slug)) {
                continue;
            }

            try {
                $claim = $this->registry->insertClaim($lockedScope->id, $binding->id, $slug, RegistryRoleEnum::CURRENT_CANONICAL);
            } catch (PDOException $exception) {
                if (! PdoDuplicateClassifier::matches($exception, 'uk_registry_scope_slug')) {
                    throw $exception;
                }
                $winner = $this->registry->findClaimByScopeSlug($lockedScope->id, $slug, true);
                if ($winner === null) {
                    throw new SlugPersistenceInvariantException('Registry unique race has no readable winner.', 0, $exception);
                }
                if ($winner->bindingId === $binding->id) {
                    $this->classifyExistingForGeneratedAssignment($winner, $binding->id);
                }
                continue;
            }

            $this->registry->activateCurrent($binding->id, $binding->revision, $claim->id);
            return $this->result($identity, $claim, $attempt + 1);
        }

        throw new SlugAllocationExhaustedException('All 1000 generated slug candidates are unavailable.');
    }

    private function insertForAssignment(int $scopeId, int $bindingId, Slug $slug): RegistryClaimRecord
    {
        try {
            return $this->registry->insertClaim($scopeId, $bindingId, $slug, RegistryRoleEnum::CURRENT_CANONICAL);
        } catch (PDOException $exception) {
            if (! PdoDuplicateClassifier::matches($exception, 'uk_registry_scope_slug')) {
                throw $exception;
            }
            $winner = $this->registry->findClaimByScopeSlug($scopeId, $slug, true);
            if ($winner === null) {
                throw new SlugPersistenceInvariantException('Registry unique race has no readable winner.', 0, $exception);
            }
            if ($winner->bindingId === $bindingId) {
                $this->classifyExistingForAssignment($winner, $bindingId);
            }
            throw new SlugAlreadyClaimedException('The requested slug was claimed concurrently.', 0, $exception);
        }
    }

    private function assertAssignmentState(RegistryBindingRecord $binding, ?int $expectedRevision): void
    {
        if ($binding->createdInCurrentTransaction) {
            if ($expectedRevision !== null) {
                throw new SlugRevisionConflictException('A missing Binding cannot satisfy an expected revision.');
            }
            return;
        }
        if ($expectedRevision === null || $binding->revision !== $expectedRevision) {
            throw new SlugRevisionConflictException('The Binding revision does not match the claim request.');
        }
        if ($binding->status !== BindingStatusEnum::RELEASED) {
            throw new SlugAssignmentNotPermittedException('Assignment requires an absent or RELEASED Binding.');
        }
    }

    private function classifyExistingForAssignment(RegistryClaimRecord $claim, int $bindingId): never
    {
        if ($claim->bindingId === $bindingId) {
            $decision = $this->sameBinding->classify(OperationTypeEnum::ASSIGN_EXACT, $claim->role);
            if ($decision === SameBindingDecisionEnum::REJECT_ASSIGNMENT) {
                throw new SlugAssignmentNotPermittedException('Assignment cannot promote or reuse a retained same-Binding role.');
            }
            throw new SlugPersistenceInvariantException('Unsupported same-Binding assignment classification.');
        }
        throw new SlugAlreadyClaimedException('The requested slug is owned by another Binding.');
    }

    private function classifyExistingForGeneratedAssignment(RegistryClaimRecord $claim, int $bindingId): void
    {
        if ($claim->bindingId === $bindingId) {
            $decision = $this->sameBinding->classify(OperationTypeEnum::ASSIGN_GENERATED, $claim->role);
            if ($decision === SameBindingDecisionEnum::REJECT_ASSIGNMENT) {
                throw new SlugAssignmentNotPermittedException('Generated assignment cannot reuse a retained same-Binding role.');
            }
            throw new SlugPersistenceInvariantException('Unsupported same-Binding generated classification.');
        }
    }

    private function result(BindingIdentityDTO $identity, RegistryClaimRecord $claim, int $attempts): OwnershipClaimResult
    {
        $binding = $this->registry->binding($identity, true);
        if ($binding === null || $binding->currentClaim === null) {
            throw new SlugPersistenceInvariantException('Claim activation did not produce a readable current Binding.');
        }

        return new OwnershipClaimResult($binding, $claim->toDto($this->profiles), $attempts);
    }
}
