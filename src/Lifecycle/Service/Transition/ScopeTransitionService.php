<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Service\Transition;

use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Slug\Lifecycle\Service\Allocation\GeneratedCandidateSequence;
use Maatify\Slug\Lifecycle\Command\TransitionScopeCommand;
use Maatify\Slug\Lifecycle\Contract\ReservedSlugPolicyInterface;
use Maatify\Slug\Lifecycle\Mapper\ResultSnapshot\ResultSnapshotEncoder;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\BindingStateResultDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeTransitionResultDTO;
use Maatify\Slug\Lifecycle\Enum\BindingStatusEnum;
use Maatify\Slug\Lifecycle\Enum\ClaimIntentModeEnum;
use Maatify\Slug\Lifecycle\Enum\HistoryEventTypeEnum;
use Maatify\Slug\Lifecycle\Enum\OperationTypeEnum;
use Maatify\Slug\Lifecycle\Enum\RegistryRoleEnum;
use Maatify\Slug\Lifecycle\Exception\SlugAlreadyClaimedException;
use Maatify\Slug\Lifecycle\Exception\SlugAllocationExhaustedException;
use Maatify\Slug\Lifecycle\Exception\SlugAssignmentNotPermittedException;
use Maatify\Slug\Lifecycle\Exception\SlugIdempotencyConflictException;
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
use Maatify\Slug\Lifecycle\Repository\Operation\OperationRecord;
use Maatify\Slug\Lifecycle\Repository\Operation\OperationReservation;
use Maatify\Slug\Canonicalization\Contract\SlugProfileInterface;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistryInterface;
use Maatify\Slug\Lifecycle\Service\Reserved\ReservationEvaluator;
use Maatify\Slug\Lifecycle\Factory\OperationKeyFactory;
use Maatify\Slug\Lifecycle\Mapper\OperationFingerprintMapper;

/** Persisted MOVE/PARALLEL scope transition implementation for WU-06. */
final readonly class ScopeTransitionService
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
        private GeneratedCandidateSequence $candidates,
        private OperationFingerprintMapper $fingerprints,
        private OperationKeyFactory $operationKeys,
    ) {
        $this->reservations = new ReservationEvaluator($reservedPolicy);
    }

    public function transitionScope(TransitionScopeCommand $command): ScopeTransitionResultDTO
    {
        $targetProfile = $this->profiles->get($command->targetScope->expectedProfileKey);
        if ($this->sameScope($command->source->scopeProfile, $command->targetScope)) {
            throw new SlugInvalidArgumentException('Transition source and target Scope identities must differ.');
        }

        $targetCandidates = $this->candidateList($targetProfile, $command);
        $fingerprint = $this->fingerprint($command, $targetCandidates[0] ?? null);
        $this->capabilities->assertInstalledSchemaSupported();

        return $this->transactions->run(function () use ($command, $targetCandidates, $fingerprint): ScopeTransitionResultDTO {
            $sourceScope = $this->registry->findScope(
                $command->source->scopeProfile->scope,
                $command->source->scopeProfile->expectedProfileKey,
            );
            if ($sourceScope === null) {
                throw new SlugNotFoundException('The transition source Scope was not found.');
            }
            $scopeRequests = [$command->source->scopeProfile, $command->targetScope];
            usort($scopeRequests, fn(ScopeProfileRequestDTO $left, ScopeProfileRequestDTO $right): int => strcmp($this->scopeKey($left), $this->scopeKey($right)));
            $lockedScopes = [];
            foreach ($scopeRequests as $scopeRequest) {
                $key = $this->scopeKey($scopeRequest);
                if ($key === $this->scopeKey($command->targetScope)) {
                    $this->registry->ensureScope($scopeRequest->scope, $scopeRequest->expectedProfileKey);
                }
                $lockedScopes[$key] = $this->registry->lockScope($scopeRequest->scope, $scopeRequest->expectedProfileKey);
            }
            $sourceScope = $lockedScopes[$this->scopeKey($command->source->scopeProfile)];
            $targetScope = $lockedScopes[$this->scopeKey($command->targetScope)];

            /** @var list<array{is_source: bool, scope: \Maatify\Slug\Lifecycle\DTO\ScopeDTO, entity: \Maatify\Slug\Lifecycle\ValueObject\EntityReference, existing: ?RegistryBindingRecord}> $bindingPlan */
            $bindingPlan = [
                ['is_source' => true, 'scope' => $sourceScope, 'entity' => $command->source->entity, 'existing' => null],
                ['is_source' => false, 'scope' => $targetScope, 'entity' => $command->source->entity, 'existing' => null],
            ];
            foreach ($bindingPlan as $index => $participant) {
                $bindingPlan[$index]['existing'] = $this->registry->findBindingRecord($participant['scope'], $participant['entity']);
            }
            usort($bindingPlan, static function (array $left, array $right): int {
                $leftId = $left['existing'] === null ? PHP_INT_MAX : $left['existing']->id;
                $rightId = $right['existing'] === null ? PHP_INT_MAX : $right['existing']->id;

                return $left['scope']->id <=> $right['scope']->id
                    ?: strcmp($left['entity']->entityType, $right['entity']->entityType)
                    ?: strcmp($left['entity']->entityKey, $right['entity']->entityKey)
                    ?: ($leftId <=> $rightId);
            });

            $sourceRecord = null;
            $targetRecord = null;
            $targetWasPresent = false;
            $targetCreated = false;
            foreach ($bindingPlan as $participant) {
                if ($participant['is_source']) {
                    if ($participant['existing'] === null) {
                        throw new SlugNotFoundException('The transition source Binding was not found.');
                    }
                    $sourceRecord = $this->registry->findBindingRecord($participant['scope'], $participant['entity'], true);
                    if ($sourceRecord === null) {
                        throw new SlugPersistenceInvariantException('Transition source Binding disappeared before lock.');
                    }
                    continue;
                }

                $targetWasPresent = $participant['existing'] !== null;
                if ($participant['existing'] === null) {
                    $targetRecord = $this->registry->lockOrCreateBindingRecord($participant['scope'], $participant['entity']);
                    $targetCreated = $targetRecord->createdInCurrentTransaction;
                } else {
                    $targetRecord = $this->registry->findBindingRecord($participant['scope'], $participant['entity'], true);
                    if ($targetRecord === null) {
                        throw new SlugPersistenceInvariantException('Transition target Binding disappeared before lock.');
                    }
                }
            }
            if ($sourceRecord === null || $targetRecord === null) {
                throw new SlugPersistenceInvariantException('Transition Binding lock plan did not produce both participants.');
            }
            $targetIdentity = new BindingIdentityDTO($command->targetScope, $command->source->entity);
            $sourceBefore = $this->requiredBinding($command->source, false);

            $existing = $this->existingOperation([$sourceRecord->id, $targetRecord->id], $command->audit);
            if ($targetWasPresent && $existing === null) {
                throw new SlugRevisionConflictException('The transition target Binding must be absent at first execution.');
            }
            if (! $targetWasPresent && ! $targetCreated && $existing === null) {
                throw new SlugRevisionConflictException('The transition target Binding was created by another operation.');
            }

            $reservation = $this->reserve(
                $command->audit,
                $fingerprint,
                [$sourceRecord->id, $targetRecord->id],
                $existing,
            );
            if ($reservation !== null && ! $reservation->created) {
                $replayed = $this->operations->decodeCommitted($reservation->operation->id, $this->profiles);
                if (! $replayed instanceof ScopeTransitionResultDTO) {
                    throw new SlugPersistenceInvariantException('Transition operation contains a non-transition snapshot.');
                }
                return $replayed->withReplayed(true);
            }

            $this->assertRevision($sourceBefore, $command->sourceExpectedRevision);
            if (! in_array($sourceBefore->state->status, [BindingStatusEnum::ACTIVE, BindingStatusEnum::INACTIVE], true) || $sourceBefore->currentClaim === null) {
                throw new SlugAssignmentNotPermittedException('Transition source must be ACTIVE or INACTIVE and have a current claim.');
            }
            if (! $targetCreated) {
                throw new SlugRevisionConflictException('The transition target Binding must be absent at first execution.');
            }

            $sourceClaims = $this->registry->findClaimsForBinding($sourceRecord->id);
            $registryKeys = array_map(
                static fn(RegistryClaimRecord $claim): array => ['scope_id' => $claim->scopeId, 'slug' => $claim->slugValue],
                $sourceClaims,
            );
            foreach ($targetCandidates as $candidate) {
                $registryKeys[] = ['scope_id' => $targetScope->id, 'slug' => $candidate->value];
            }
            $lockedClaims = $this->registry->findClaimsForKeys($registryKeys, true);
            $targetClaims = array_values(array_filter(
                $lockedClaims,
                static fn(RegistryClaimRecord $claim): bool => $claim->scopeId === $targetScope->id,
            ));
            $targetClaim = $this->selectTargetClaim($targetScope->id, $targetRecord, $targetClaims, $targetCandidates, $command);

            $sourceEvents = [];
            if ($command->mode->value === 'MOVE') {
                $sourceEvents[] = $this->claimEvent(
                    HistoryEventTypeEnum::SCOPE_TRANSITIONED_OUT,
                    $command->source,
                    $sourceBefore->currentClaim->slug,
                    $command->targetScope,
                    $command->source->entity,
                    $reservation,
                    $command->audit,
                );
            }
            $targetEvents = [$this->claimEvent(
                HistoryEventTypeEnum::SCOPE_TRANSITIONED_IN,
                $targetIdentity,
                $targetClaim->toDto($this->profiles)->slug,
                $command->source->scopeProfile,
                $command->source->entity,
                $reservation,
                $command->audit,
            )];
            /** @var list<\Maatify\Slug\Lifecycle\DTO\HistoryEventDTO> $sourceHistory */
            $sourceHistory = $this->appendEvents($sourceBefore->id, $sourceBefore->state->historySequence, $sourceEvents);
            /** @var list<\Maatify\Slug\Lifecycle\DTO\HistoryEventDTO> $targetHistory */
            $targetHistory = $this->appendEvents($targetRecord->id, 0, $targetEvents);

            $targetClaimId = $targetClaim->id;
            $this->registry->mutateBinding($targetRecord->id, $targetRecord->revision, BindingStatusEnum::ACTIVE, $targetClaimId, 1);
            if ($command->mode->value === 'MOVE') {
                $this->registry->mutateBinding(
                    $sourceBefore->id,
                    $sourceBefore->state->revision,
                    BindingStatusEnum::INACTIVE,
                    $sourceBefore->currentClaim->id,
                    $sourceBefore->state->historySequence + 1,
                );
            }

            $sourceAfter = $this->requiredBinding($command->source, false);
            $targetAfter = $this->requiredBinding($targetIdentity, false);
            $sourceParticipant = new BindingStateResultDTO(
                $sourceBefore,
                $sourceAfter,
                $command->mode->value === 'MOVE',
                $sourceAfter->state->revision,
                $sourceHistory,
            );
            $targetParticipant = new BindingStateResultDTO(null, $targetAfter, true, $targetAfter->state->revision, $targetHistory);
            $result = new ScopeTransitionResultDTO(
                OperationTypeEnum::TRANSITION_SCOPE,
                $this->operationKey($reservation),
                false,
                $command->mode,
                true,
                $sourceParticipant,
                $targetParticipant,
                $sourceBefore,
                $sourceAfter,
                null,
                $targetAfter,
                $sourceBefore->currentClaim->slug,
                $targetClaim->toDto($this->profiles)->slug,
                $sourceAfter->state->revision,
                $targetAfter->state->revision,
                array_merge($sourceHistory, $targetHistory),
            );
            if ($reservation !== null) {
                $snapshot = ResultSnapshotEncoder::encode($result);
                $this->operations->commitSnapshot(
                    $reservation->operation->id,
                    new ResultSnapshotMetadata('transition', 1, OperationTypeEnum::TRANSITION_SCOPE, $reservation->operation->operationKey, $snapshot),
                    $this->profiles,
                );
            }

            return $result;
        });
    }

    /** @return list<Slug> */
    private function candidateList(SlugProfileInterface $profile, TransitionScopeCommand $command): array
    {
        if ($command->targetClaimIntent->mode === ClaimIntentModeEnum::EXACT) {
            return [$profile->canonicalizeClaim($command->targetClaimIntent->value)->slug];
        }

        return $this->candidates->fromSource($profile, $command->targetClaimIntent->value);
    }

    /**
     * @param list<RegistryClaimRecord> $claims
     * @param list<Slug> $candidates
     */
    private function selectTargetClaim(
        int $scopeId,
        RegistryBindingRecord $target,
        array $claims,
        array $candidates,
        TransitionScopeCommand $command,
    ): RegistryClaimRecord {
        foreach ($candidates as $candidate) {
            $existing = $this->claimForSlug($claims, $candidate);
            if ($existing !== null) {
                if ($command->targetClaimIntent->mode === ClaimIntentModeEnum::EXACT) {
                    if ($this->reservations->isReserved($command->targetScope->scope, $candidate)) {
                        throw new SlugReservedException('The exact transition target claim is reserved.');
                    }
                    throw new SlugAlreadyClaimedException('The exact transition target claim is already owned.');
                }
                continue;
            }
            if ($this->reservations->isReserved($command->targetScope->scope, $candidate)) {
                if ($command->targetClaimIntent->mode === ClaimIntentModeEnum::EXACT) {
                    throw new SlugReservedException('The exact transition target claim is reserved.');
                }
                continue;
            }
            $claim = $this->registry->insertClaim($scopeId, $target->id, $candidate, RegistryRoleEnum::CURRENT_CANONICAL);
            if ($claim !== null) {
                return $claim;
            }
            {
                if ($command->targetClaimIntent->mode === ClaimIntentModeEnum::EXACT) {
                    throw new SlugAlreadyClaimedException('The exact transition target claim was claimed concurrently.');
                }
            }
        }

        throw new SlugAllocationExhaustedException('All transition target candidates are unavailable.');
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

    private function requiredBinding(BindingIdentityDTO $identity, bool $forUpdate): BindingDTO
    {
        $binding = $this->registry->binding($identity, $forUpdate);
        if ($binding === null) {
            throw new SlugPersistenceInvariantException('Transition Binding disappeared during mutation.');
        }
        return $binding;
    }

    private function assertRevision(BindingDTO $binding, int $expectedRevision): void
    {
        if ($binding->state->revision !== $expectedRevision) {
            throw new SlugRevisionConflictException('The transition source revision does not match.');
        }
    }

    private function claimEvent(
        HistoryEventTypeEnum $type,
        BindingIdentityDTO $identity,
        Slug $slug,
        ScopeProfileRequestDTO $relatedScope,
        \Maatify\Slug\Lifecycle\ValueObject\EntityReference $relatedEntity,
        ?OperationReservation $reservation,
        AuditContextDTO $audit,
    ): HistoryEventDraft {
        return new HistoryEventDraft(
            0,
            0,
            $type,
            $identity->scopeProfile->scope,
            $identity->entity,
            $slug,
            null,
            RegistryRoleEnum::CURRENT_CANONICAL,
            null,
            $relatedScope->scope,
            $relatedEntity,
            $reservation?->operation->id,
            $reservation?->operation->operationKey,
            $audit,
            $this->clock->now(),
        );
    }

    /**
     * @param list<HistoryEventDraft> $drafts
     * @return list<\Maatify\Slug\Lifecycle\DTO\HistoryEventDTO>
     */
    private function appendEvents(int $bindingId, int $startingSequence, array $drafts): array
    {
        $events = [];
        foreach ($drafts as $index => $draft) {
            $events[] = $this->history->append(new HistoryEventDraft(
                $bindingId,
                $startingSequence + $index + 1,
                $draft->eventType,
                $draft->scopeSnapshot,
                $draft->entitySnapshot,
                $draft->slugSnapshot,
                $draft->previousSlugSnapshot,
                $draft->claimRoleSnapshot,
                $draft->previousClaimRoleSnapshot,
                $draft->relatedScope,
                $draft->relatedEntity,
                $draft->operationId,
                $draft->operationKey,
                $draft->audit,
                $draft->occurredAt,
                $draft->originalOccurredAt,
            ));
        }
        return $events;
    }

    private function operationKey(?OperationReservation $reservation): ?string
    {
        return $reservation === null ? null : $reservation->operation->operationKey;
    }

    /** @param list<int> $bindingIds */
    private function existingOperation(array $bindingIds, AuditContextDTO $audit): ?OperationRecord
    {
        if ($audit->idempotencyKey === null) {
            return null;
        }
        foreach ($bindingIds as $bindingId) {
            $existing = $this->operations->findByParticipant($bindingId, $audit->idempotencyKey, true);
            if ($existing !== null) {
                return $existing;
            }
        }
        return null;
    }

    /** @param list<int> $bindingIds */
    private function reserve(
        AuditContextDTO $audit,
        string $fingerprint,
        array $bindingIds,
        ?OperationRecord $existing,
    ): ?OperationReservation {
        if ($audit->idempotencyKey === null) {
            return null;
        }
        $key = $existing === null ? $this->operationKeys->create('Unable to allocate a transition operation key.') : $existing->operationKey;
        $participants = [
            new OperationParticipant($bindingIds[0], $audit->idempotencyKey, 'SOURCE'),
            new OperationParticipant($bindingIds[1], $audit->idempotencyKey, 'TARGET'),
        ];
        return $this->operations->reserve($key, OperationTypeEnum::TRANSITION_SCOPE, $fingerprint, 'transition', 1, $participants);
    }

    private function fingerprint(TransitionScopeCommand $command, ?Slug $exactCandidate): string
    {
        $intentValue = $command->targetClaimIntent->mode === ClaimIntentModeEnum::EXACT
            ? $exactCandidate?->value
            : $command->targetClaimIntent->value;
        $payload = [
            'contract_version' => 1,
            'operation_type' => OperationTypeEnum::TRANSITION_SCOPE->value,
            'source_scope' => $this->scopeArray($command->source->scopeProfile),
            'source_binding' => $this->entityArray($command->source),
            'target_scope' => $this->scopeArray($command->targetScope),
            'target_binding' => null,
            'claim_intent' => [
                'mode' => $command->targetClaimIntent->mode->value,
                'value' => $intentValue,
            ],
            'source_replacement_intent' => null,
            'transition_mode' => $command->mode->value,
            'expected_revision' => null,
            'source_expected_revision' => $command->sourceExpectedRevision,
            'target_expected_revision' => null,
            'original_occurred_at' => null,
        ];
        return $this->fingerprints->fingerprint($payload, 'Transition fingerprint encoding failed.');
    }

    /** @return array{namespace: string, locale_key: ?string, context_key: ?string, profile_key: string} */
    private function scopeArray(ScopeProfileRequestDTO $request): array
    {
        return [
            'namespace' => $request->scope->namespace,
            'locale_key' => $request->scope->localeKey,
            'context_key' => $request->scope->contextKey,
            'profile_key' => $request->expectedProfileKey->value,
        ];
    }

    /** @return array{entity_type: string, entity_key: string} */
    private function entityArray(BindingIdentityDTO $identity): array
    {
        return ['entity_type' => $identity->entity->entityType, 'entity_key' => $identity->entity->entityKey];
    }

    private function sameScope(ScopeProfileRequestDTO $left, ScopeProfileRequestDTO $right): bool
    {
        return $left->scope->namespace === $right->scope->namespace
            && $left->scope->localeKey === $right->scope->localeKey
            && $left->scope->contextKey === $right->scope->contextKey;
    }

    private function scopeKey(ScopeProfileRequestDTO $request): string
    {
        return $request->scope->namespace . "\0" . ($request->scope->localeKey ?? '') . "\0" . ($request->scope->contextKey ?? '');
    }
}
