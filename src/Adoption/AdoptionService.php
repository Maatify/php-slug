<?php

declare(strict_types=1);

namespace Maatify\Slug\Adoption;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PDOException;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Slug\Command\AdoptAliasCommand;
use Maatify\Slug\Command\AdoptCurrentCommand;
use Maatify\Slug\Command\AdoptHistoricalCommand;
use Maatify\Slug\Contract\ReservedSlugPolicyInterface;
use Maatify\Slug\Contract\ResultSnapshot\ResultSnapshotEncoder;
use Maatify\Slug\DTO\AdoptionResultDTO;
use Maatify\Slug\DTO\AuditContextDTO;
use Maatify\Slug\DTO\BindingDTO;
use Maatify\Slug\DTO\BindingIdentityDTO;
use Maatify\Slug\Enum\BindingStatusEnum;
use Maatify\Slug\Enum\ClaimIntentModeEnum;
use Maatify\Slug\Enum\HistoryEventTypeEnum;
use Maatify\Slug\Enum\OperationTypeEnum;
use Maatify\Slug\Enum\RegistryRoleEnum;
use Maatify\Slug\Exception\SlugAlreadyClaimedException;
use Maatify\Slug\Exception\SlugAssignmentNotPermittedException;
use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\Exception\SlugNotFoundException;
use Maatify\Slug\Exception\SlugPersistenceInvariantException;
use Maatify\Slug\Exception\SlugReservedException;
use Maatify\Slug\Exception\SlugRevisionConflictException;
use Maatify\Slug\History\HistoryEventDraft;
use Maatify\Slug\Identity\IdentityValidator;
use Maatify\Slug\Identity\Slug;
use Maatify\Slug\Infrastructure\Persistence\PDO\Connection\PdoCapabilityGuard;
use Maatify\Slug\Infrastructure\Persistence\PDO\Connection\PdoDuplicateClassifier;
use Maatify\Slug\Infrastructure\Persistence\PDO\History\PdoHistoryRepository;
use Maatify\Slug\Infrastructure\Persistence\PDO\Operations\PdoOperationRepository;
use Maatify\Slug\Infrastructure\Persistence\PDO\Registry\PdoRegistryRepository;
use Maatify\Slug\Infrastructure\Persistence\PDO\Registry\RegistryBindingRecord;
use Maatify\Slug\Infrastructure\Persistence\PDO\Registry\RegistryClaimRecord;
use Maatify\Slug\Internal\ResultSnapshot\ResultSnapshotMetadata;
use Maatify\Slug\Internal\Transaction\PdoTransactionCoordinator;
use Maatify\Slug\Persistence\Contract\OperationParticipant;
use Maatify\Slug\Persistence\Contract\OperationReservation;
use Maatify\Slug\Profile\Contracts\SlugProfileRegistryInterface;
use Maatify\Slug\Reserved\ReservationEvaluator;

/** Persisted current, historical, and alias adoption for WU-06. */
final readonly class AdoptionService
{
    private ReservationEvaluator $reservations;

    public function __construct(
        private SlugProfileRegistryInterface $profiles,
        private PdoRegistryRepository $registry,
        private PdoHistoryRepository $history,
        private PdoOperationRepository $operations,
        private PdoTransactionCoordinator $transactions,
        private PdoCapabilityGuard $capabilities,
        private ClockInterface $clock,
        ReservedSlugPolicyInterface $reservedPolicy,
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
            $record = $this->registry->lockOrCreateBinding($scope, $identity->entity);
            $before = $record->createdInCurrentTransaction ? null : $this->requiredBinding($identity, true);

            $reservation = $this->reserve($operation, $fingerprint, $record->id, $audit);
            if ($reservation !== null && ! $reservation->created) {
                $replayed = $this->operations->decodeCommitted($reservation->operation->id, $this->profiles);
                if (! $replayed instanceof AdoptionResultDTO) {
                    throw new SlugPersistenceInvariantException('Adoption operation contains a non-adoption snapshot.');
                }

                return $replayed->withReplayed(true);
            }

            $this->assertState($record, $before, $expectedRevision, $currentAdoption);
            $existing = $this->registry->findClaimByScopeSlug($scope->id, $slug, true);
            if ($existing !== null) {
                throw new SlugAlreadyClaimedException('The adopted slug is already claimed in the requested Scope.');
            }
            if ($this->reservations->isReserved($scope->scope, $slug)) {
                throw new SlugReservedException('The adopted slug is reserved.');
            }

            try {
                $claim = $this->registry->insertClaim($scope->id, $record->id, $slug, $role);
            } catch (PDOException $exception) {
                if (! PdoDuplicateClassifier::matches($exception, 'uk_registry_scope_slug')) {
                    throw $exception;
                }
                throw new SlugAlreadyClaimedException('The adopted slug was claimed concurrently.', 0, $exception);
            }

            $occurredAt = $this->clock->now();
            IdentityValidator::assertDateTimeRange($occurredAt, 'occurredAt');
            $original = $originalOccurredAt ?? $occurredAt;
            IdentityValidator::assertDateTimeRange($original, 'originalOccurredAt');
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

    private function assertState(RegistryBindingRecord $record, ?BindingDTO $before, ?int $expectedRevision, bool $currentAdoption): void
    {
        if ($currentAdoption) {
            if ($record->createdInCurrentTransaction) {
                if ($expectedRevision !== null) {
                    throw new SlugRevisionConflictException('First current adoption requires a NULL expected revision.');
                }
                return;
            }
            if ($before === null) {
                throw new SlugPersistenceInvariantException('Existing adoption Binding cannot be read.');
            }
            if ($before->state->status !== BindingStatusEnum::RELEASED) {
                throw new SlugAssignmentNotPermittedException('Current adoption requires an absent or RELEASED Binding.');
            }
            if ($expectedRevision === null || $before->state->revision !== $expectedRevision) {
                throw new SlugRevisionConflictException('Released Binding revision does not match current adoption.');
            }
            if ($before->currentClaim !== null) {
                throw new SlugPersistenceInvariantException('Released Binding cannot retain a current claim.');
            }
            return;
        }

        if ($record->createdInCurrentTransaction || $before === null) {
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
        $binding = $this->registry->binding($identity, $forUpdate);
        if ($binding === null) {
            throw new SlugPersistenceInvariantException('Adoption Binding disappeared during mutation.');
        }

        return $binding;
    }

    private function reserve(OperationTypeEnum $operation, string $fingerprint, int $bindingId, AuditContextDTO $audit): ?OperationReservation
    {
        if ($audit->idempotencyKey === null) {
            return null;
        }
        $existing = $this->operations->findByParticipant($bindingId, $audit->idempotencyKey, true);
        $key = $existing === null ? $this->newOperationKey() : $existing->operationKey;

        return $this->operations->reserve(
            $key,
            $operation,
            $fingerprint,
            'adoption',
            1,
            [new OperationParticipant($bindingId, $audit->idempotencyKey, 'SINGLE')],
        );
    }

    private function newOperationKey(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable $throwable) {
            throw new SlugPersistenceInvariantException('Unable to allocate an adoption operation key.', 0, $throwable);
        }
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
        try {
            return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        } catch (JsonException $exception) {
            throw new SlugPersistenceInvariantException('Adoption fingerprint encoding failed.', 0, $exception);
        }
    }
}
