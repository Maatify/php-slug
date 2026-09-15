<?php

declare(strict_types=1);

namespace Maatify\Slug\Contract\ResultSnapshot;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\Slug\DTO\AdoptionResultDTO;
use Maatify\Slug\DTO\AliasDTO;
use Maatify\Slug\DTO\BindingDTO;
use Maatify\Slug\DTO\BindingIdentityDTO;
use Maatify\Slug\DTO\BindingStateDTO;
use Maatify\Slug\DTO\BindingStateResultDTO;
use Maatify\Slug\DTO\DTOAssertions;
use Maatify\Slug\DTO\HistoryEventDTO;
use Maatify\Slug\DTO\RegistryClaimDTO;
use Maatify\Slug\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\DTO\ScopeTransitionResultDTO;
use Maatify\Slug\DTO\SlugMutationResultDTO;
use Maatify\Slug\DTO\AtomicTransferResultDTO;
use Maatify\Slug\Enum\BindingStatusEnum;
use Maatify\Slug\Enum\ChangeTypeEnum;
use Maatify\Slug\Enum\HistoryEventTypeEnum;
use Maatify\Slug\Enum\OperationTypeEnum;
use Maatify\Slug\Enum\RegistryRoleEnum;
use Maatify\Slug\Enum\ScopeTransitionModeEnum;
use Maatify\Slug\Exception\SlugPersistenceInvariantException;
use Maatify\Slug\Identity\EntityReference;
use Maatify\Slug\Identity\IdentityValidator;
use Maatify\Slug\Identity\Slug;
use Maatify\Slug\Identity\SlugProfileKey;
use Maatify\Slug\Profile\Contracts\SlugProfileInterface;
use Maatify\Slug\Profile\Contracts\SlugProfileRegistryInterface;
use Maatify\Slug\Scope\Value\SlugScope;
use Throwable;

final class ResultSnapshotDecoder
{
    public static function decode(
        string $json,
        SlugProfileRegistryInterface $profiles,
        ?string $expectedResultType = null,
        ?int $expectedSchemaVersion = null,
        ?string $expectedOperationType = null,
        ?string $expectedOperationKey = null,
    ): SlugMutationResultDTO|ScopeTransitionResultDTO|AtomicTransferResultDTO|AdoptionResultDTO {
        try {
            self::assertNoDuplicateObjectKeys($json);
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $root = self::object($decoded, 'root');
            self::keys($root, ['result_type', 'result_schema_version', 'result'], 'root');
            $resultType = self::string($root['result_type'], 'root.result_type');
            $version = self::integer($root['result_schema_version'], 'root.result_schema_version');
            if ($version !== 1 || ($expectedSchemaVersion !== null && $version !== $expectedSchemaVersion)) {
                self::fail('Unknown or mismatched result schema version.');
            }
            if ($expectedResultType !== null && $resultType !== $expectedResultType) {
                self::fail('Result type does not match its storage column.');
            }
            $result = match ($resultType) {
                'mutation' => self::decodeMutation(self::object($root['result'], 'result'), $profiles),
                'transition' => self::decodeTransition(self::object($root['result'], 'result'), $profiles),
                'transfer' => self::decodeTransfer(self::object($root['result'], 'result'), $profiles),
                'adoption' => self::decodeAdoption(self::object($root['result'], 'result'), $profiles),
                default => throw new SlugPersistenceInvariantException('Unknown result type.'),
            };

            $operationType = $result->operationType->value;
            if ($expectedOperationType !== null && $operationType !== $expectedOperationType) {
                self::fail('Operation type does not match its storage column.');
            }
            if ($expectedOperationKey !== null && $result->operationKey !== $expectedOperationKey) {
                self::fail('Operation key does not match its storage column.');
            }
            if ($result->replayed) {
                self::fail('Stored result snapshots must contain replayed=false.');
            }
            self::assertResultOperationKeys($result);

            return $result->withReplayed(true);
        } catch (SlugPersistenceInvariantException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new SlugPersistenceInvariantException('Invalid result snapshot.', 0, $exception);
        }
    }

    /** @param array<string, mixed> $result */
    private static function decodeMutation(array $result, SlugProfileRegistryInterface $profiles, bool $allowNestedTransfer = false): SlugMutationResultDTO
    {
        self::keys($result, [
            'operation_type', 'operation_key', 'replayed', 'before', 'after', 'affected_claims',
            'previous_slug', 'current_slug', 'change_type', 'revision', 'history_events',
        ], 'mutation');
        $after = self::binding(self::object($result['after'], 'mutation.after'), $profiles);
        $profile = self::profile($after->identity->scopeProfile, $profiles);
        $claims = [];
        foreach (self::list($result['affected_claims'], 'mutation.affected_claims') as $index => $claim) {
            $claims[] = self::claim(self::object($claim, sprintf('mutation.affected_claims[%d]', $index)), $profiles);
        }
        $history = self::historyList($result['history_events'], 'mutation.history_events', $profile, [$after->id]);
        $operation = self::operation($result['operation_type'], 'mutation.operation_type');
        $decoded = new SlugMutationResultDTO(
            $operation,
            self::nullableString($result['operation_key'], 'mutation.operation_key'),
            self::boolean($result['replayed'], 'mutation.replayed'),
            $result['before'] === null ? null : self::binding(self::object($result['before'], 'mutation.before'), $profiles),
            $after,
            $claims,
            self::nullableSlug($result['previous_slug'], 'mutation.previous_slug', $profile),
            self::nullableSlug($result['current_slug'], 'mutation.current_slug', $profile),
            self::change($result['change_type'], 'mutation.change_type'),
            self::integer($result['revision'], 'mutation.revision'),
            $history,
        );
        if (in_array($operation, [
            OperationTypeEnum::TRANSITION_SCOPE,
            OperationTypeEnum::ADOPT_CURRENT,
            OperationTypeEnum::ADOPT_HISTORICAL,
            OperationTypeEnum::ADOPT_ALIAS,
        ], true) || ($operation === OperationTypeEnum::ATOMIC_TRANSFER && ! $allowNestedTransfer)) {
            self::fail('Aggregate operation cannot use the mutation result type.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $result */
    private static function decodeTransition(array $result, SlugProfileRegistryInterface $profiles): ScopeTransitionResultDTO
    {
        self::keys($result, [
            'operation_type', 'operation_key', 'replayed', 'mode', 'target_created',
            'source_result', 'target_result', 'source_before', 'source_after', 'target_before',
            'target_after', 'source_claim', 'target_claim', 'source_revision', 'target_revision',
            'history_events',
        ], 'transition');
        $sourceAfter = self::binding(self::object($result['source_after'], 'transition.source_after'), $profiles);
        $targetAfter = self::binding(self::object($result['target_after'], 'transition.target_after'), $profiles);
        $sourceProfile = self::profile($sourceAfter->identity->scopeProfile, $profiles);
        $operation = self::operation($result['operation_type'], 'transition.operation_type');
        if ($operation !== OperationTypeEnum::TRANSITION_SCOPE) {
            self::fail('Transition result has an invalid operation type.');
        }
        return new ScopeTransitionResultDTO(
            $operation,
            self::nullableString($result['operation_key'], 'transition.operation_key'),
            self::boolean($result['replayed'], 'transition.replayed'),
            self::mode($result['mode'], 'transition.mode'),
            self::boolean($result['target_created'], 'transition.target_created'),
            self::bindingStateResult(self::object($result['source_result'], 'transition.source_result'), $profiles),
            self::bindingStateResult(self::object($result['target_result'], 'transition.target_result'), $profiles),
            $result['source_before'] === null ? null : self::binding(self::object($result['source_before'], 'transition.source_before'), $profiles),
            $sourceAfter,
            $result['target_before'] === null ? null : self::binding(self::object($result['target_before'], 'transition.target_before'), $profiles),
            $targetAfter,
            self::nullableSlug($result['source_claim'], 'transition.source_claim', $sourceProfile),
            self::nullableSlug($result['target_claim'], 'transition.target_claim', self::profile($targetAfter->identity->scopeProfile, $profiles)),
            self::integer($result['source_revision'], 'transition.source_revision'),
            self::integer($result['target_revision'], 'transition.target_revision'),
            self::historyList($result['history_events'], 'transition.history_events', $sourceProfile, [$sourceAfter->id, $targetAfter->id]),
        );
    }

    /** @param array<string, mixed> $result */
    private static function decodeTransfer(array $result, SlugProfileRegistryInterface $profiles): AtomicTransferResultDTO
    {
        self::keys($result, [
            'operation_type', 'operation_key', 'replayed', 'source_result', 'target_result',
            'transferred_claim', 'source_replacement_result', 'source_revision', 'target_revision',
            'history_events',
        ], 'transfer');
        $sourceResult = self::bindingStateResult(self::object($result['source_result'], 'transfer.source_result'), $profiles);
        $targetResult = self::bindingStateResult(self::object($result['target_result'], 'transfer.target_result'), $profiles);
        $claim = self::claim(self::object($result['transferred_claim'], 'transfer.transferred_claim'), $profiles);
        $profile = self::profile($claim->binding->scopeProfile, $profiles);
        $replacement = $result['source_replacement_result'] === null
            ? null
            : self::decodeMutation(self::object($result['source_replacement_result'], 'transfer.source_replacement_result'), $profiles, true);
        $operation = self::operation($result['operation_type'], 'transfer.operation_type');
        if ($operation !== OperationTypeEnum::ATOMIC_TRANSFER) {
            self::fail('Transfer result has an invalid operation type.');
        }
        return new AtomicTransferResultDTO(
            $operation,
            self::nullableString($result['operation_key'], 'transfer.operation_key'),
            self::boolean($result['replayed'], 'transfer.replayed'),
            $sourceResult,
            $targetResult,
            $claim,
            $replacement,
            self::integer($result['source_revision'], 'transfer.source_revision'),
            self::integer($result['target_revision'], 'transfer.target_revision'),
            self::historyList($result['history_events'], 'transfer.history_events', $profile, [$sourceResult->after->id, $targetResult->after->id]),
        );
    }

    /** @param array<string, mixed> $result */
    private static function decodeAdoption(array $result, SlugProfileRegistryInterface $profiles): AdoptionResultDTO
    {
        self::keys($result, [
            'operation_type', 'operation_key', 'replayed', 'before', 'after', 'adopted_claim', 'history_event',
        ], 'adoption');
        $after = self::binding(self::object($result['after'], 'adoption.after'), $profiles);
        $profile = self::profile($after->identity->scopeProfile, $profiles);
        $operation = self::operation($result['operation_type'], 'adoption.operation_type');
        if (! in_array($operation, [
            OperationTypeEnum::ADOPT_CURRENT,
            OperationTypeEnum::ADOPT_HISTORICAL,
            OperationTypeEnum::ADOPT_ALIAS,
        ], true)) {
            self::fail('Adoption result has an invalid operation type.');
        }
        return new AdoptionResultDTO(
            $operation,
            self::nullableString($result['operation_key'], 'adoption.operation_key'),
            self::boolean($result['replayed'], 'adoption.replayed'),
            $result['before'] === null ? null : self::binding(self::object($result['before'], 'adoption.before'), $profiles),
            $after,
            self::claim(self::object($result['adopted_claim'], 'adoption.adopted_claim'), $profiles),
            self::history(self::object($result['history_event'], 'adoption.history_event'), $profile),
        );
    }

    /** @param array<string, mixed> $value */
    private static function binding(array $value, SlugProfileRegistryInterface $profiles): BindingDTO
    {
        self::keys($value, ['id', 'identity', 'state', 'current_claim', 'created_at', 'updated_at'], 'binding');
        $identity = self::identity(self::object($value['identity'], 'binding.identity'));
        $profile = self::profile($identity->scopeProfile, $profiles);
        $state = self::bindingState(self::object($value['state'], 'binding.state'), $profile);
        return new BindingDTO(
            self::integer($value['id'], 'binding.id'),
            $identity,
            $state,
            $value['current_claim'] === null ? null : self::claim(self::object($value['current_claim'], 'binding.current_claim'), $profiles),
            self::date($value['created_at'], 'binding.created_at'),
            self::date($value['updated_at'], 'binding.updated_at'),
        );
    }

    /** @param array<string, mixed> $value */
    private static function bindingState(array $value, SlugProfileInterface $profile): BindingStateDTO
    {
        self::keys($value, ['status', 'current_slug', 'revision', 'history_sequence'], 'binding.state');
        return new BindingStateDTO(
            self::bindingStatus($value['status'], 'binding.state.status'),
            self::nullableSlug($value['current_slug'], 'binding.state.current_slug', $profile),
            self::integer($value['revision'], 'binding.state.revision'),
            self::integer($value['history_sequence'], 'binding.state.history_sequence'),
        );
    }

    /** @param array<string, mixed> $value */
    private static function claim(array $value, SlugProfileRegistryInterface $profiles): RegistryClaimDTO
    {
        self::keys($value, ['id', 'binding', 'slug', 'role', 'claimed_at', 'updated_at'], 'claim');
        $binding = self::identity(self::object($value['binding'], 'claim.binding'));
        return new RegistryClaimDTO(
            self::integer($value['id'], 'claim.id'),
            $binding,
            self::slug($value['slug'], 'claim.slug', self::profile($binding->scopeProfile, $profiles)),
            self::role($value['role'], 'claim.role'),
            self::date($value['claimed_at'], 'claim.claimed_at'),
            self::date($value['updated_at'], 'claim.updated_at'),
        );
    }

    /** @param array<string, mixed> $value */
    private static function identity(array $value): BindingIdentityDTO
    {
        self::keys($value, ['scope_profile', 'entity'], 'identity');
        $scopeProfile = self::scopeProfile(self::object($value['scope_profile'], 'identity.scope_profile'));
        $entity = self::object($value['entity'], 'identity.entity');
        self::keys($entity, ['entity_type', 'entity_key'], 'identity.entity');
        return new BindingIdentityDTO(
            $scopeProfile,
            new EntityReference(
                self::string($entity['entity_type'], 'identity.entity.entity_type'),
                self::string($entity['entity_key'], 'identity.entity.entity_key'),
            ),
        );
    }

    /** @param array<string, mixed> $value */
    private static function scopeProfile(array $value): ScopeProfileRequestDTO
    {
        self::keys($value, ['scope', 'expected_profile_key'], 'scope_profile');
        $scope = self::object($value['scope'], 'scope_profile.scope');
        self::keys($scope, ['namespace', 'locale_key', 'context_key'], 'scope_profile.scope');
        return new ScopeProfileRequestDTO(
            new SlugScope(
                self::string($scope['namespace'], 'scope_profile.scope.namespace'),
                self::nullableString($scope['locale_key'], 'scope_profile.scope.locale_key'),
                self::nullableString($scope['context_key'], 'scope_profile.scope.context_key'),
            ),
            new SlugProfileKey(self::string($value['expected_profile_key'], 'scope_profile.expected_profile_key')),
        );
    }

    /** @param array<string, mixed> $value */
    private static function bindingStateResult(array $value, SlugProfileRegistryInterface $profiles): BindingStateResultDTO
    {
        self::keys($value, ['before', 'after', 'mutated', 'revision', 'history_events'], 'binding_state_result');
        $after = self::binding(self::object($value['after'], 'binding_state_result.after'), $profiles);
        return new BindingStateResultDTO(
            $value['before'] === null ? null : self::binding(self::object($value['before'], 'binding_state_result.before'), $profiles),
            $after,
            self::boolean($value['mutated'], 'binding_state_result.mutated'),
            self::integer($value['revision'], 'binding_state_result.revision'),
            self::historyList($value['history_events'], 'binding_state_result.history_events', self::profile($after->identity->scopeProfile, $profiles), [$after->id]),
        );
    }

    /** @return list<HistoryEventDTO> */
    /**
     * @param list<int>|null $bindingIds
     * @return list<HistoryEventDTO>
     */
    private static function historyList(mixed $value, string $path, SlugProfileInterface $profile, ?array $bindingIds = null): array
    {
        $events = [];
        foreach (self::list($value, $path) as $index => $event) {
            $events[] = self::history(self::object($event, sprintf('%s[%d]', $path, $index)), $profile);
        }
        if ($bindingIds === null) {
            DTOAssertions::orderedHistory($events, $path);
        } else {
            DTOAssertions::participantHistory($events, $bindingIds, $path);
        }
        return $events;
    }

    /** @param array<string, mixed> $value */
    private static function history(array $value, SlugProfileInterface $profile): HistoryEventDTO
    {
        self::keys($value, [
            'id', 'binding_id', 'sequence_no', 'event_type', 'scope_snapshot', 'entity_snapshot',
            'slug_snapshot', 'previous_slug_snapshot', 'claim_role_snapshot', 'previous_claim_role_snapshot',
            'related_scope', 'related_entity', 'operation_key', 'actor_key', 'reason', 'correlation_key',
            'occurred_at', 'original_occurred_at',
        ], 'history');
        $scope = self::object($value['scope_snapshot'], 'history.scope_snapshot');
        self::keys($scope, ['namespace', 'locale_key', 'context_key'], 'history.scope_snapshot');
        $entity = self::object($value['entity_snapshot'], 'history.entity_snapshot');
        self::keys($entity, ['entity_type', 'entity_key'], 'history.entity_snapshot');
        $relatedScope = $value['related_scope'] === null ? null : self::scope(self::object($value['related_scope'], 'history.related_scope'));
        $relatedEntity = $value['related_entity'] === null ? null : self::entity(self::object($value['related_entity'], 'history.related_entity'));
        return new HistoryEventDTO(
            self::integer($value['id'], 'history.id'),
            self::integer($value['binding_id'], 'history.binding_id'),
            self::integer($value['sequence_no'], 'history.sequence_no'),
            self::eventType($value['event_type'], 'history.event_type'),
            new SlugScope(
                self::string($scope['namespace'], 'history.scope_snapshot.namespace'),
                self::nullableString($scope['locale_key'], 'history.scope_snapshot.locale_key'),
                self::nullableString($scope['context_key'], 'history.scope_snapshot.context_key'),
            ),
            new EntityReference(
                self::string($entity['entity_type'], 'history.entity_snapshot.entity_type'),
                self::string($entity['entity_key'], 'history.entity_snapshot.entity_key'),
            ),
            self::nullableSlug($value['slug_snapshot'], 'history.slug_snapshot', $profile),
            self::nullableSlug($value['previous_slug_snapshot'], 'history.previous_slug_snapshot', $profile),
            self::nullableRole($value['claim_role_snapshot'], 'history.claim_role_snapshot'),
            self::nullableRole($value['previous_claim_role_snapshot'], 'history.previous_claim_role_snapshot'),
            $relatedScope,
            $relatedEntity,
            self::nullableString($value['operation_key'], 'history.operation_key'),
            self::nullableString($value['actor_key'], 'history.actor_key'),
            self::nullableString($value['reason'], 'history.reason'),
            self::nullableString($value['correlation_key'], 'history.correlation_key'),
            self::date($value['occurred_at'], 'history.occurred_at'),
            $value['original_occurred_at'] === null ? null : self::date($value['original_occurred_at'], 'history.original_occurred_at'),
        );
    }

    /** @param array<string, mixed> $value */
    private static function scope(array $value): SlugScope
    {
        self::keys($value, ['namespace', 'locale_key', 'context_key'], 'scope');
        return new SlugScope(
            self::string($value['namespace'], 'scope.namespace'),
            self::nullableString($value['locale_key'], 'scope.locale_key'),
            self::nullableString($value['context_key'], 'scope.context_key'),
        );
    }

    /** @param array<string, mixed> $value */
    private static function entity(array $value): EntityReference
    {
        self::keys($value, ['entity_type', 'entity_key'], 'entity');
        return new EntityReference(
            self::string($value['entity_type'], 'entity.entity_type'),
            self::string($value['entity_key'], 'entity.entity_key'),
        );
    }

    private static function profile(ScopeProfileRequestDTO $request, SlugProfileRegistryInterface $profiles): SlugProfileInterface
    {
        try {
            return $profiles->get($request->expectedProfileKey);
        } catch (Throwable $exception) {
            throw new SlugPersistenceInvariantException('Snapshot references an unavailable slug profile.', 0, $exception);
        }
    }

    private static function slug(mixed $value, string $path, SlugProfileInterface $profile): Slug
    {
        $candidate = self::string($value, $path);
        try {
            return Slug::fromProfile($profile, $candidate);
        } catch (Throwable $exception) {
            throw new SlugPersistenceInvariantException(sprintf('Invalid slug at %s.', $path), 0, $exception);
        }
    }

    private static function nullableSlug(mixed $value, string $path, SlugProfileInterface $profile): ?Slug
    {
        return $value === null ? null : self::slug($value, $path, $profile);
    }

    private static function date(mixed $value, string $path): DateTimeImmutable
    {
        $string = self::string($value, $path);
        if (preg_match('/\\A\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}\\.\\d{6}Z\\z/', $string) !== 1) {
            self::fail(sprintf('Invalid UTC timestamp at %s.', $path));
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s.u\\Z', $string, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0)) || $date->format('Y-m-d\\TH:i:s.u\\Z') !== $string) {
            self::fail(sprintf('Invalid UTC timestamp at %s.', $path));
        }
        IdentityValidator::assertDateTimeRange($date, $path);
        return $date;
    }

    private static function operation(mixed $value, string $path): OperationTypeEnum
    {
        return self::enum($value, $path, OperationTypeEnum::class);
    }

    private static function change(mixed $value, string $path): ChangeTypeEnum
    {
        return self::enum($value, $path, ChangeTypeEnum::class);
    }

    private static function mode(mixed $value, string $path): ScopeTransitionModeEnum
    {
        return self::enum($value, $path, ScopeTransitionModeEnum::class);
    }

    private static function bindingStatus(mixed $value, string $path): BindingStatusEnum
    {
        return self::enum($value, $path, BindingStatusEnum::class);
    }

    private static function role(mixed $value, string $path): RegistryRoleEnum
    {
        return self::enum($value, $path, RegistryRoleEnum::class);
    }

    private static function nullableRole(mixed $value, string $path): ?RegistryRoleEnum
    {
        return $value === null ? null : self::role($value, $path);
    }

    private static function eventType(mixed $value, string $path): HistoryEventTypeEnum
    {
        return self::enum($value, $path, HistoryEventTypeEnum::class);
    }

    /**
     * @template T of \BackedEnum
     * @param class-string<T> $enum
     * @return T
     */
    private static function enum(mixed $value, string $path, string $enum): \BackedEnum
    {
        $token = self::string($value, $path);
        $parsed = $enum::tryFrom($token);
        if ($parsed === null) {
            self::fail(sprintf('Unknown enum token at %s.', $path));
        }
        /** @var T $parsed */
        return $parsed;
    }

    /** @return list<mixed> */
    private static function list(mixed $value, string $path): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            self::fail(sprintf('%s must be a JSON list.', $path));
        }
        return $value;
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value, string $path): array
    {
        if (! is_array($value) || array_is_list($value)) {
            self::fail(sprintf('%s must be a JSON object.', $path));
        }
        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $expected
     */
    private static function keys(array $value, array $expected, string $path): void
    {
        $actual = array_keys($value);
        sort($actual);
        $sortedExpected = $expected;
        sort($sortedExpected);
        if ($actual !== $sortedExpected) {
            self::fail(sprintf('Unexpected key set at %s.', $path));
        }
    }

    private static function string(mixed $value, string $path): string
    {
        if (! is_string($value)) {
            self::fail(sprintf('%s must be a JSON string.', $path));
        }
        return $value;
    }

    private static function nullableString(mixed $value, string $path): ?string
    {
        if ($value !== null && ! is_string($value)) {
            self::fail(sprintf('%s must be a JSON string or null.', $path));
        }
        return $value;
    }

    private static function integer(mixed $value, string $path): int
    {
        if (! is_int($value)) {
            self::fail(sprintf('%s must be a JSON integer.', $path));
        }
        return $value;
    }

    private static function boolean(mixed $value, string $path): bool
    {
        if (! is_bool($value)) {
            self::fail(sprintf('%s must be a JSON boolean.', $path));
        }
        return $value;
    }

    private static function fail(string $message): never
    {
        throw new SlugPersistenceInvariantException($message);
    }

    private static function assertResultOperationKeys(
        SlugMutationResultDTO|ScopeTransitionResultDTO|AtomicTransferResultDTO|AdoptionResultDTO $result,
    ): void {
        if ($result instanceof SlugMutationResultDTO) {
            self::assertHistoryOperationKeys($result->historyEvents, $result->operationKey, 'mutation.history_events');
            return;
        }
        if ($result instanceof ScopeTransitionResultDTO) {
            self::assertHistoryOperationKeys($result->historyEvents, $result->operationKey, 'transition.history_events');
            self::assertHistoryOperationKeys($result->sourceResult->historyEvents, $result->operationKey, 'transition.source_result.history_events');
            self::assertHistoryOperationKeys($result->targetResult->historyEvents, $result->operationKey, 'transition.target_result.history_events');
            return;
        }
        if ($result instanceof AtomicTransferResultDTO) {
            self::assertHistoryOperationKeys($result->historyEvents, $result->operationKey, 'transfer.history_events');
            self::assertHistoryOperationKeys($result->sourceResult->historyEvents, $result->operationKey, 'transfer.source_result.history_events');
            self::assertHistoryOperationKeys($result->targetResult->historyEvents, $result->operationKey, 'transfer.target_result.history_events');
            if ($result->sourceReplacementResult !== null) {
                self::assertHistoryOperationKeys($result->sourceReplacementResult->historyEvents, $result->operationKey, 'transfer.source_replacement_result.history_events');
            }
            return;
        }
        self::assertHistoryOperationKeys([$result->historyEvent], $result->operationKey, 'adoption.history_event');
    }

    /** @param list<HistoryEventDTO> $events */
    private static function assertHistoryOperationKeys(array $events, ?string $operationKey, string $path): void
    {
        foreach ($events as $index => $event) {
            if ($event->operationKey !== $operationKey) {
                self::fail(sprintf('Operation key mismatch at %s[%d].', $path, $index));
            }
        }
    }

    private static function assertNoDuplicateObjectKeys(string $json): void
    {
        $length = strlen($json);
        $objects = [];
        $start = 0;
        $inString = false;
        $escaped = false;
        for ($index = 0; $index < $length; $index++) {
            $character = $json[$index];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $inString = false;
                    $end = $index;
                    $cursor = $index + 1;
                    while ($cursor < $length && in_array($json[$cursor], [" ", "\t", "\r", "\n"], true)) {
                        $cursor++;
                    }
                    if ($cursor < $length && $json[$cursor] === ':' && $objects !== []) {
                        $raw = substr($json, $start, $end - $start + 1);
                        $key = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                        if (! is_string($key)) {
                            self::fail('Invalid JSON object key.');
                        }
                        $objectIndex = count($objects) - 1;
                        if (isset($objects[$objectIndex][$key])) {
                            self::fail('Duplicate JSON object key.');
                        }
                        $objects[$objectIndex][$key] = true;
                    }
                }
                continue;
            }
            if ($character === '"') {
                $inString = true;
                $start = $index;
                continue;
            }
            if ($character === '{') {
                $objects[] = [];
            } elseif ($character === '}' && $objects !== []) {
                array_pop($objects);
            }
        }
    }
}
