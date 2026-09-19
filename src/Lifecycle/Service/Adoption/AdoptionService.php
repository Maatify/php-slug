<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Service\Adoption;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Slug\Lifecycle\Command\AdoptAliasCommand;
use Maatify\Slug\Lifecycle\Command\AdoptCurrentCommand;
use Maatify\Slug\Lifecycle\Command\AdoptHistoricalCommand;
use Maatify\Slug\Lifecycle\Command\CommandAssertions;
use Maatify\Slug\Lifecycle\Contract\ReservedSlugPolicyInterface;
use Maatify\Slug\Lifecycle\Mapper\ResultSnapshot\ResultSnapshotEncoder;
use Maatify\Slug\Lifecycle\DTO\AdoptionResultDTO;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\Enum\BindingStatusEnum;
use Maatify\Slug\Lifecycle\Enum\ClaimIntentModeEnum;
use Maatify\Slug\Lifecycle\Enum\HistoryEventTypeEnum;
use Maatify\Slug\Lifecycle\Enum\OperationTypeEnum;
use Maatify\Slug\Lifecycle\Enum\RegistryRoleEnum;
use Maatify\Slug\Lifecycle\Exception\SlugAlreadyClaimedException;
use Maatify\Slug\Lifecycle\Exception\SlugAssignmentNotPermittedException;
use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\Exception\SlugNotFoundException;
use Maatify\Slug\Lifecycle\Exception\SlugPersistenceInvariantException;
use Maatify\Slug\Lifecycle\Exception\SlugReservedException;
use Maatify\Slug\Lifecycle\Exception\SlugRevisionConflictException;
use Maatify\Slug\Lifecycle\Repository\History\HistoryEventDraft;
use Maatify\Slug\Canonicalization\ValueObject\Slug;
use Maatify\Slug\Lifecycle\Repository\Pdo\Registry\RegistryBindingRecord;
use Maatify\Slug\Lifecycle\Repository\Pdo\Registry\RegistryClaimRecord;
use Maatify\Slug\Lifecycle\Repository\CapabilityGuardInterface;
use Maatify\Slug\Lifecycle\Repository\History\HistoryRepositoryInterface;
use Maatify\Slug\Lifecycle\Repository\Operation\OperationPersistenceInterface;
use Maatify\Slug\Lifecycle\Repository\Operation\ResultSnapshotMetadata;
use Maatify\Slug\Lifecycle\Repository\Registry\RegistryRepositoryInterface;
use Maatify\Slug\Lifecycle\Repository\Transaction\TransactionCoordinatorInterface;
use Maatify\Slug\Lifecycle\Repository\Operation\OperationParticipant;
use Maatify\Slug\Lifecycle\Repository\Operation\OperationReservation;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistryInterface;
use Maatify\Slug\Lifecycle\Service\Reserved\ReservationEvaluator;
use Maatify\Slug\Lifecycle\Factory\OperationKeyFactory;
use Maatify\Slug\Lifecycle\Mapper\OperationFingerprintMapper;

/** Persisted current, historical, and alias adoption for WU-06. */
final readonly class AdoptionService
{
    private ReservationEvaluator $reservations;

    public function __construct(
        private SlugProfileRegistryInterface $profiles,
        private RegistryRepositoryInterface $registry,
        private HistoryRepositoryInterface $history,
        private OperationPersistenceInterface $operations,
        private TransactionCoordinatorInterface $transactions,
        private CapabilityGuardInterface $capabilities,
        private ClockInterface $clock,
        ReservedSlugPolicyInterface $reservedPolicy,
        private OperationFingerprintMapper $fingerprints,
        private OperationKeyFactory $operationKeys,
    ) {
        $this->reservations = new ReservationEvaluator($reservedPolicy);
    }

    public function adoptCurrent(AdoptCurrentCommand $command): AdoptionResultDTO
    {
        return $this->adopt(
            OperationTypeEnum::ADOPT_CURRENT,
            HistoryEventTypeEnum::ADOPTED_CURRENT,
            RegistryRoleEnum::CURRENT_CANONICAL,
            $command->binding,
            $command->slugCandidate,
            $command->originalOccurredAt,
            $command->expectedRevision,
            $command->audit,
            true,
        );
    }

    public function adoptHistorical(AdoptHistoricalCommand $command): AdoptionResultDTO
    {
        return $this->adopt(
            OperationTypeEnum::ADOPT_HISTORICAL,
            HistoryEventTypeEnum::ADOPTED_HISTORICAL,
            RegistryRoleEnum::HISTORICAL_CANONICAL,
            $command->binding,
            $command->slugCandidate,
            $command->originalOccurredAt,
            $command->expectedRevision,
            $command->audit,
            false,
        );
    }

    public function adoptAlias(AdoptAliasCommand $command): AdoptionResultDTO
    {
        return $this->adopt(
            OperationTypeEnum::ADOPT_ALIAS,
            HistoryEventTypeEnum::ADOPTED_ALIAS,
            RegistryRoleEnum::ACTIVE_ALIAS,
            $command->binding,
            $command->slugCandidate,
            $command->originalOccurredAt,
            $command->expectedRevision,
            $command->audit,
            false,
        );
    }

    private function adopt(
        OperationTypeEnum $operation,
        HistoryEventTypeEnum $eventType,
        RegistryRoleEnum $role,
        BindingIdentityDTO $identity,
        string $candidate,
        ?DateTimeImmutable $originalOccurredAt,
        ?int $expectedRevision,
        AuditContextDTO $audit,
        bool $currentAdoption,
    ): AdoptionResultDTO {
        $profile = $this->profiles->get($identity->scopeProfile->expectedProfileKey);
        $slug = $profile->canonicalizeClaim($candidate)->slug;
        $fingerprint = $this->fingerprint($operation, $identity, $slug, $expectedRevision, $originalOccurredAt);
        $this->capabilities->assertInstalledSchemaSupported();

        return $this->transactions->run(function () use ($operation, $eventType, $role, $identity, $slug, $originalOccurredAt, $expectedRevision, $audit, $currentAdoption, $fingerprint): AdoptionResultDTO {
            $scope = $this->registry->ensureScope($identity->scopeProfile->scope, $identity->scopeProfile->expectedProfileKey);
            $scope = $this->registry->lockScope($scope->scope, $scope->profileKey);
            $record = $this->registry->findBindingRecord($scope, $identity->entity, true);
            if ($record === null && $currentAdoption) {
                $record = $this->registry->lockOrCreateBindingRecord($scope, $identity->entity);
            }
            $before = $record === null || $record->createdInCurrentTransaction ? null : $this->requiredBinding($identity, false);
            if ($record === null) {
                throw new SlugNotFoundException('Historical or alias adoption requires an existing Binding.');
            }

            $reservation = $this->reserve($operation, $fingerprint, $record->id, $audit);
            if ($reservation !== null && ! $reservation->created) {
                $replayed = $this->operations->decodeCommitted($reservation->operation->id, $this->profiles);
                if (! $replayed instanceof AdoptionResultDTO) {
                    throw new SlugPersistenceInvariantException('Adoption operation contains a non-adoption snapshot.');
                }

                return $replayed->withReplayed(true);
            }

            $this->assertState($record, $before, $expectedRevision, $currentAdoption);
            $claims = $before === null ? [] : $this->registry->findClaimsForBinding($record->id);
            $registryKeys = array_map(
                static fn(RegistryClaimRecord $claim): array => ['scope_id' => $claim->scopeId, 'slug' => $claim->slugValue],
                $claims,
            );
            $registryKeys[] = ['scope_id' => $scope->id, 'slug' => $slug->value];
            $lockedClaims = $this->registry->findClaimsForKeys($registryKeys, true);
            $existing = $this->claimForSlug($lockedClaims, $slug);
            if ($existing !== null) {
                throw new SlugAlreadyClaimedException('The adopted slug is already claimed in the requested Scope.');
            }
            if ($this->reservations->isReserved($scope->scope, $slug)) {
                throw new SlugReservedException('The adopted slug is reserved.');
            }

            $occurredAt = $this->clock->now();
            CommandAssertions::dateTime($occurredAt, 'occurredAt');
            $original = $originalOccurredAt ?? $occurredAt;
            CommandAssertions::dateTime($original, 'originalOccurredAt');

            $claim = $this->registry->insertClaim($scope->id, $record->id, $slug, $role);
            if ($claim === null) {
                throw new SlugAlreadyClaimedException('The adopted slug was claimed concurrently.');
            }

            $event = $this->history->append(new HistoryEventDraft(
                $record->id,
                ($before?->state->historySequence ?? 0) + 1,
                $eventType,
                $identity->scopeProfile->scope,
                $identity->entity,
                $slug,
                null,
                $role,
                null,
                null,
                null,
                $reservation?->operation->id,
                $reservation?->operation->operationKey,
                $audit,
                $occurredAt,
                $original,
            ));

            $this->registry->mutateBinding(
                $record->id,
                $record->revision,
                $currentAdoption ? BindingStatusEnum::ACTIVE : $record->status,
                $currentAdoption ? $claim->id : $record->currentRegistryId,
                ($before?->state->historySequence ?? 0) + 1,
            );
            $after = $this->requiredBinding($identity, true);

            $result = new AdoptionResultDTO(
                $operation,
                $reservation?->operation->operationKey,
                false,
                $before,
                $after,
                $claim->toDto($this->profiles),
                $event,
            );
            if ($reservation !== null) {
                $snapshot = ResultSnapshotEncoder::encode($result);
                $this->operations->commitSnapshot(
                    $reservation->operation->id,
                    new ResultSnapshotMetadata('adoption', 1, $operation, $reservation->operation->operationKey, $snapshot),
                    $this->profiles,
                );
            }

            return $result;
        });
    }

    private function assertState(?RegistryBindingRecord $record, ?BindingDTO $before, ?int $expectedRevision, bool $currentAdoption): void
    {
        if ($currentAdoption) {
            if ($record === null || $record->createdInCurrentTransaction) {
                if ($expectedRevision !== null) {
                    throw new SlugRevisionConflictException('First current adoption requires a NULL expected revision.');
                }
                return;
            }
            if ($before === null) {
                throw new SlugPersistenceInvariantException('Existing adoption Binding cannot be read.');
            }
            if ($expectedRevision === null || $before->state->revision !== $expectedRevision) {
                throw new SlugRevisionConflictException('Existing Binding revision does not match current adoption.');
            }
            if ($before->state->status !== BindingStatusEnum::RELEASED) {
                throw new SlugAssignmentNotPermittedException('Current adoption requires an absent or RELEASED Binding.');
            }
            if ($before->currentClaim !== null) {
                throw new SlugPersistenceInvariantException('Released Binding cannot retain a current claim.');
            }
            return;
        }

        if ($record === null || $record->createdInCurrentTransaction || $before === null) {
            throw new SlugNotFoundException('Historical or alias adoption requires an existing Binding.');
        }
        if (! in_array($before->state->status, [BindingStatusEnum::ACTIVE, BindingStatusEnum::INACTIVE], true) || $before->currentClaim === null) {
            throw new SlugAssignmentNotPermittedException('Historical or alias adoption requires a current-bearing Binding.');
        }
        if ($before->state->revision !== $expectedRevision) {
            throw new SlugRevisionConflictException('Adoption Binding revision does not match.');
        }
    }

    private function requiredBinding(BindingIdentityDTO $identity, bool $forUpdate): BindingDTO
    {
        $binding = $this->registry->binding($identity, false);
        if ($binding === null) {
            throw new SlugPersistenceInvariantException('Adoption Binding disappeared during mutation.');
        }

        return $binding;
    }

    /** @param list<RegistryClaimRecord> $claims */
    private function claimForSlug(array $claims, Slug $slug): ?RegistryClaimRecord
    {
        foreach ($claims as $claim) {
            if ($claim->slugValue === $slug->value) {
                return $claim;
            }
        }

        return null;
    }

    private function reserve(OperationTypeEnum $operation, string $fingerprint, int $bindingId, AuditContextDTO $audit): ?OperationReservation
    {
        if ($audit->idempotencyKey === null) {
            return null;
        }
        $existing = $this->operations->findByParticipant($bindingId, $audit->idempotencyKey, true);
        $key = $existing === null ? $this->operationKeys->create('Unable to allocate an adoption operation key.') : $existing->operationKey;

        return $this->operations->reserve(
            $key,
            $operation,
            $fingerprint,
            'adoption',
            1,
            [new OperationParticipant($bindingId, $audit->idempotencyKey, 'SINGLE')],
        );
    }

    private function fingerprint(OperationTypeEnum $operation, BindingIdentityDTO $identity, Slug $slug, ?int $expectedRevision, ?DateTimeImmutable $originalOccurredAt): string
    {
        $payload = [
            'contract_version' => 1,
            'operation_type' => $operation->value,
            'source_scope' => [
                'namespace' => $identity->scopeProfile->scope->namespace,
                'locale_key' => $identity->scopeProfile->scope->localeKey,
                'context_key' => $identity->scopeProfile->scope->contextKey,
                'profile_key' => $identity->scopeProfile->expectedProfileKey->value,
            ],
            'source_binding' => [
                'entity_type' => $identity->entity->entityType,
                'entity_key' => $identity->entity->entityKey,
            ],
            'target_scope' => null,
            'target_binding' => null,
            'claim_intent' => ['mode' => ClaimIntentModeEnum::EXACT->value, 'value' => $slug->value],
            'source_replacement_intent' => null,
            'transition_mode' => null,
            'expected_revision' => $expectedRevision,
            'source_expected_revision' => null,
            'target_expected_revision' => null,
            'original_occurred_at' => $originalOccurredAt?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
        ];
        return $this->fingerprints->fingerprint($payload, 'Adoption fingerprint encoding failed.');
    }
}
