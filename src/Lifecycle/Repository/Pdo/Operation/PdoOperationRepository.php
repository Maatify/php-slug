<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Repository\Pdo\Operation;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Slug\Lifecycle\DTO\AdoptionResultDTO;
use Maatify\Slug\Lifecycle\DTO\AtomicTransferResultDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeTransitionResultDTO;
use Maatify\Slug\Lifecycle\DTO\SlugMutationResultDTO;
use Maatify\Slug\Lifecycle\Enum\OperationTypeEnum;
use Maatify\Slug\Lifecycle\Exception\SlugIdempotencyConflictException;
use Maatify\Slug\Lifecycle\Exception\SlugPersistenceInvariantException;
use Maatify\Slug\Lifecycle\Repository\Pdo\Support\PdoCapabilityGuard;
use Maatify\Slug\Lifecycle\Repository\Pdo\Support\PdoDuplicateClassifier;
use Maatify\Slug\Lifecycle\Repository\Pdo\Support\PdoRowHydrator;
use Maatify\Slug\Lifecycle\Repository\Operation\OperationSnapshotState;
use Maatify\Slug\Lifecycle\Repository\Operation\ResultSnapshotMetadata;
use Maatify\Slug\Lifecycle\Mapper\ResultSnapshot\ResultSnapshotStorage;
use Maatify\Slug\Lifecycle\Repository\Pdo\Transaction\PdoTransactionCoordinator;
use Maatify\Slug\Lifecycle\Repository\Pdo\Transaction\PdoTransactionRollback;
use Maatify\Slug\Lifecycle\Exception\SlugTransactionParticipationException;
use Maatify\Slug\Lifecycle\Repository\Operation\OperationParticipant;
use Maatify\Slug\Lifecycle\Repository\Operation\OperationPersistenceInterface;
use Maatify\Slug\Lifecycle\Repository\Operation\OperationRecord;
use Maatify\Slug\Lifecycle\Repository\Operation\OperationReservation;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistryInterface;
use Throwable;

final readonly class PdoOperationRepository implements OperationPersistenceInterface
{
    private PdoCapabilityGuard $capabilities;

    private PdoTransactionCoordinator $transactions;

    public function __construct(
        private PDO $pdo,
        private ClockInterface $clock,
        ?PdoCapabilityGuard $capabilities = null,
        ?PdoTransactionCoordinator $transactions = null,
        private ?Closure $beforeParticipantInsert = null,
    ) {
        $this->capabilities = $capabilities ?? new PdoCapabilityGuard($pdo);
        $this->transactions = $transactions ?? new PdoTransactionCoordinator($pdo);
    }

    /** @param list<OperationParticipant> $participants */
    public function reserve(
        string $operationKey,
        OperationTypeEnum $operationType,
        string $requestFingerprint,
        string $resultType,
        int $resultSchemaVersion,
        array $participants,
    ): OperationReservation {
        $this->assertReservationInput($operationKey, $requestFingerprint, $resultType, $resultSchemaVersion, $participants);
        $this->assertResultOperationCompatibility($resultType, $operationType);
        $this->assertCallerTransaction();
        $this->capabilities->assertInstalledSchemaSupported();

        /** @var OperationReservation|OperationRecord $result */
        $result = $this->transactions->run(function () use ($operationKey, $operationType, $requestFingerprint, $resultType, $resultSchemaVersion, $participants): OperationReservation|PdoTransactionRollback {
            foreach ($participants as $participant) {
                $existing = $this->findParticipantRow($participant->bindingId, $participant->idempotencyKey, true);
                if ($existing !== null) {
                    $operation = $this->operationFromRow($existing);
                    $this->assertReservationMatches($operation, $participants, $operationKey, $operationType, $requestFingerprint, $resultType, $resultSchemaVersion);
                    $this->assertCommittedReplayEvidence($operation);

                    return new OperationReservation($operation, false);
                }
            }

            $operationId = $this->insertOperation($operationKey, $operationType, $requestFingerprint, $resultType, $resultSchemaVersion);
            if ($operationId instanceof PdoTransactionRollback) {
                return $operationId;
            }
            if ($this->beforeParticipantInsert !== null) {
                ($this->beforeParticipantInsert)($operationId);
            }
            try {
                foreach ($participants as $participant) {
                    $this->insertParticipant($operationId, $participant);
                }
            } catch (PDOException $exception) {
                if (! PdoDuplicateClassifier::matches($exception, 'uk_operation_binding_idempotency')) {
                    throw $exception;
                }

                $existing = $this->findExistingParticipant($participants);
                if ($existing === null) {
                    throw new SlugPersistenceInvariantException('Operation participant duplicate has no readable evidence.', 0, $exception);
                }

                return new PdoTransactionRollback($existing);
            }

            $operation = $this->findOperationById($operationId, true);
            if ($operation === null) {
                throw new SlugPersistenceInvariantException('Operation disappeared after reservation.');
            }

            return new OperationReservation($operation, true);
        });

        if ($result instanceof OperationRecord) {
            $this->assertReservationMatches($result, $participants, $operationKey, $operationType, $requestFingerprint, $resultType, $resultSchemaVersion);
            $this->assertCommittedReplayEvidence($result);

            return new OperationReservation($result, false);
        }
        return $result;
    }

    public function findByParticipant(int $bindingId, string $idempotencyKey, bool $forUpdate = false): ?OperationRecord
    {
        if ($bindingId < 0) {
            throw new SlugPersistenceInvariantException('Binding id cannot be negative.');
        }
        OperationParticipant::assertIdempotencyKey($idempotencyKey);
        $row = $this->findParticipantRow($bindingId, $idempotencyKey, $forUpdate);

        return $row === null ? null : $this->operationFromRow($row);
    }

    public function commitSnapshot(int $operationId, ResultSnapshotMetadata $metadata, SlugProfileRegistryInterface $profiles): void
    {
        if ($operationId < 0) {
            throw new SlugPersistenceInvariantException('Operation id cannot be negative.');
        }
        $this->assertCallerTransaction();
        $this->capabilities->assertInstalledSchemaSupported();

        $this->transactions->run(function () use ($operationId, $metadata, $profiles): void {
            $operation = $this->findOperationById($operationId, true);
            if ($operation === null) {
                throw new SlugPersistenceInvariantException('Operation does not exist.');
            }
            $state = new OperationSnapshotState(
                $operation->operationKey,
                $operation->operationType,
                $operation->resultType,
                $operation->resultSchemaVersion,
                $operation->status,
                $operation->resultSnapshot,
                $operation->completedAt,
            );
            ResultSnapshotStorage::assertImmutableUpdate($state, $metadata);
            ResultSnapshotStorage::decodeCommitted($metadata, $profiles);

            $statement = $this->pdo->prepare(
                'UPDATE maa_slug_operations SET result_type = :result_type, result_schema_version = :result_schema_version, '
                . 'result_snapshot = :result_snapshot, status = :committed_status, completed_at = :completed_at '
                . 'WHERE id = :operation_id AND status = :in_progress_status '
                . 'AND result_snapshot IS NULL AND completed_at IS NULL',
            );
            if ($statement === false) {
                throw new SlugPersistenceInvariantException('Unable to prepare the operation commit.');
            }
            $statement->execute([
                'result_type' => $metadata->resultType,
                'result_schema_version' => $metadata->resultSchemaVersion,
                'result_snapshot' => $metadata->snapshot,
                'committed_status' => 'COMMITTED',
                'completed_at' => $this->timestamp(),
                'operation_id' => $operationId,
                'in_progress_status' => 'IN_PROGRESS',
            ]);
            if ($statement->rowCount() !== 1) {
                throw new SlugPersistenceInvariantException('Operation snapshot was not committed exactly once.');
            }
        });
    }

    public function decodeCommitted(int $operationId, SlugProfileRegistryInterface $profiles): SlugMutationResultDTO|ScopeTransitionResultDTO|AtomicTransferResultDTO|AdoptionResultDTO
    {
        $operation = $this->findOperationById($operationId, false);
        if ($operation === null || $operation->status !== 'COMMITTED' || $operation->resultSnapshot === null || $operation->completedAt === null) {
            throw new SlugPersistenceInvariantException('Only a complete COMMITTED operation can be replayed.');
        }

        $metadata = new ResultSnapshotMetadata(
            $operation->resultType,
            $operation->resultSchemaVersion,
            $operation->operationType,
            $operation->operationKey,
            $operation->resultSnapshot,
        );

        return ResultSnapshotStorage::decodeCommitted($metadata, $profiles);
    }

    /** @param list<OperationParticipant> $participants */
    private function assertReservationInput(string $operationKey, string $requestFingerprint, string $resultType, int $resultSchemaVersion, array $participants): void
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $operationKey) !== 1 || preg_match('/\A[a-f0-9]{64}\z/', $requestFingerprint) !== 1) {
            throw new SlugPersistenceInvariantException('Operation reservation identity is invalid.');
        }
        if (! in_array($resultType, ['mutation', 'transition', 'transfer', 'adoption'], true) || $resultSchemaVersion !== 1 || $participants === []) {
            throw new SlugPersistenceInvariantException('Operation reservation metadata is invalid.');
        }
        $keys = [];
        $roles = [];
        foreach ($participants as $participant) {
            $keys[] = $participant->idempotencyKey;
            $roles[] = $participant->role;
        }
        if (count(array_unique($keys, SORT_STRING)) !== 1) {
            throw new SlugPersistenceInvariantException('All participants must share one idempotency key.');
        }
        if (count($participants) === 1 && $roles !== ['SINGLE']) {
            throw new SlugPersistenceInvariantException('A single participant operation requires the SINGLE role.');
        }
        if (count($participants) === 2 && $roles !== ['SOURCE', 'TARGET']) {
            throw new SlugPersistenceInvariantException('A two participant operation requires SOURCE then TARGET roles.');
        }
        if (count($participants) > 2 || count(array_unique(array_map(static fn(OperationParticipant $participant): int => $participant->bindingId, $participants))) !== count($participants)) {
            throw new SlugPersistenceInvariantException('Operation participants must be distinct and bounded.');
        }
    }

    private function assertResultOperationCompatibility(string $resultType, OperationTypeEnum $operationType): void
    {
        $valid = match ($resultType) {
            'mutation' => ! in_array($operationType, [OperationTypeEnum::TRANSITION_SCOPE, OperationTypeEnum::ATOMIC_TRANSFER, OperationTypeEnum::ADOPT_CURRENT, OperationTypeEnum::ADOPT_HISTORICAL, OperationTypeEnum::ADOPT_ALIAS], true),
            'transition' => $operationType === OperationTypeEnum::TRANSITION_SCOPE,
            'transfer' => $operationType === OperationTypeEnum::ATOMIC_TRANSFER,
            'adoption' => in_array($operationType, [OperationTypeEnum::ADOPT_CURRENT, OperationTypeEnum::ADOPT_HISTORICAL, OperationTypeEnum::ADOPT_ALIAS], true),
            default => false,
        };
        if (! $valid) {
            throw new SlugPersistenceInvariantException('Operation and Result Snapshot types are incompatible.');
        }
    }

    /** @param list<OperationParticipant> $participants */
    private function assertReservationMatches(OperationRecord $operation, array $participants, string $operationKey, OperationTypeEnum $operationType, string $requestFingerprint, string $resultType, int $resultSchemaVersion): void
    {
        if ($operation->operationKey !== $operationKey || $operation->operationType !== $operationType || $operation->requestFingerprint !== $requestFingerprint || $operation->resultType !== $resultType || $operation->resultSchemaVersion !== $resultSchemaVersion) {
            throw new SlugIdempotencyConflictException('Idempotency evidence does not match the requested operation.');
        }

        $existingParticipants = $this->participantsForOperation($operation->id);
        $expected = array_map(static fn(OperationParticipant $participant): string => $participant->role . ':' . $participant->bindingId . ':' . $participant->idempotencyKey, $participants);
        $actual = array_map(static fn(OperationParticipant $participant): string => $participant->role . ':' . $participant->bindingId . ':' . $participant->idempotencyKey, $existingParticipants);
        sort($expected);
        sort($actual);
        if ($expected !== $actual) {
            throw new SlugIdempotencyConflictException('Idempotency participants do not match the requested operation.');
        }
    }

    private function assertCommittedReplayEvidence(OperationRecord $operation): void
    {
        if ($operation->status !== 'COMMITTED' || $operation->resultSnapshot === null || $operation->completedAt === null) {
            throw new SlugPersistenceInvariantException('Existing operation evidence is incomplete and cannot be replayed.');
        }
    }

    private function insertOperation(string $operationKey, OperationTypeEnum $operationType, string $requestFingerprint, string $resultType, int $resultSchemaVersion): int|PdoTransactionRollback
    {
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO maa_slug_operations '
                . '(operation_key, operation_type, request_fingerprint, result_type, result_schema_version, result_snapshot, status, created_at, completed_at) '
                . 'VALUES (:operation_key, :operation_type, :request_fingerprint, :result_type, :result_schema_version, NULL, :status, :created_at, NULL)',
            );
            if ($statement === false) {
                throw new SlugPersistenceInvariantException('Unable to prepare the operation reservation.');
            }
            $statement->execute([
                'operation_key' => $operationKey,
                'operation_type' => $operationType->value,
                'request_fingerprint' => $requestFingerprint,
                'result_type' => $resultType,
                'result_schema_version' => $resultSchemaVersion,
                'status' => 'IN_PROGRESS',
                'created_at' => $this->timestamp(),
            ]);
        } catch (PDOException $exception) {
            if (! PdoDuplicateClassifier::matches($exception, 'uk_operation_key')) {
                throw $exception;
            }
            $existing = $this->findOperationByKey($operationKey, true);
            if ($existing === null) {
                throw new SlugPersistenceInvariantException('Operation key duplicate has no readable evidence.', 0, $exception);
            }
            return new PdoTransactionRollback($existing);
        }

        $id = filter_var($this->pdo->lastInsertId(), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new SlugPersistenceInvariantException('Operation insert did not return a valid id.');
        }

        return $id;
    }

    private function assertCallerTransaction(): void
    {
        if (! $this->pdo->inTransaction()) {
            throw new SlugTransactionParticipationException(
                'Operation reservation and snapshot commit require a caller-owned transaction; an IN_PROGRESS operation cannot be committed independently.',
            );
        }
    }

    private function insertParticipant(int $operationId, OperationParticipant $participant): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO maa_slug_operation_bindings (idempotency_key, operation_id, binding_id, participant_role) '
            . 'VALUES (:idempotency_key, :operation_id, :binding_id, :participant_role)',
        );
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare the operation participant reservation.');
        }
        $statement->execute([
            'idempotency_key' => $participant->idempotencyKey,
            'operation_id' => $operationId,
            'binding_id' => $participant->bindingId,
            'participant_role' => $participant->role,
        ]);
    }

    /** @return array<string, mixed>|null */
    private function findParticipantRow(int $bindingId, string $idempotencyKey, bool $forUpdate): ?array
    {
        $suffix = $forUpdate ? ' FOR UPDATE' : '';
        $statement = $this->pdo->prepare(
            'SELECT o.id, o.operation_key, o.operation_type, o.request_fingerprint, o.result_type, '
            . 'o.result_schema_version, o.result_snapshot, o.status, o.created_at, o.completed_at '
            . 'FROM maa_slug_operation_bindings p INNER JOIN maa_slug_operations o ON o.id = p.operation_id '
            . 'WHERE p.binding_id = :binding_id AND p.idempotency_key = :idempotency_key' . $suffix,
        );
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare the operation participant lookup.');
        }
        $statement->execute([
            'binding_id' => $bindingId,
            'idempotency_key' => $idempotencyKey,
        ]);
        return PdoRowHydrator::one($statement->fetch(PDO::FETCH_ASSOC));
    }

    /** @param list<OperationParticipant> $participants */
    private function findExistingParticipant(array $participants): ?OperationRecord
    {
        foreach ($participants as $participant) {
            $row = $this->findParticipantRow($participant->bindingId, $participant->idempotencyKey, true);
            if ($row !== null) {
                return $this->operationFromRow($row);
            }
        }

        return null;
    }

    private function findOperationById(int $id, bool $forUpdate): ?OperationRecord
    {
        $suffix = $forUpdate ? ' FOR UPDATE' : '';
        $statement = $this->pdo->prepare(
            'SELECT id, operation_key, operation_type, request_fingerprint, result_type, result_schema_version, '
            . 'result_snapshot, status, created_at, completed_at FROM maa_slug_operations WHERE id = :id' . $suffix,
        );
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare the operation lookup.');
        }
        $statement->execute(['id' => $id]);
        $row = PdoRowHydrator::one($statement->fetch(PDO::FETCH_ASSOC));

        return $row === null ? null : $this->operationFromRow($row);
    }

    private function findOperationByKey(string $operationKey, bool $forUpdate): ?OperationRecord
    {
        $suffix = $forUpdate ? ' FOR UPDATE' : '';
        $statement = $this->pdo->prepare('SELECT id, operation_key, operation_type, request_fingerprint, result_type, result_schema_version, result_snapshot, status, created_at, completed_at FROM maa_slug_operations WHERE operation_key = :operation_key' . $suffix);
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare the operation-key lookup.');
        }
        $statement->execute(['operation_key' => $operationKey]);
        $row = PdoRowHydrator::one($statement->fetch(PDO::FETCH_ASSOC));

        return $row === null ? null : $this->operationFromRow($row);
    }

    /** @return list<OperationParticipant> */
    private function participantsForOperation(int $operationId): array
    {
        $statement = $this->pdo->prepare('SELECT binding_id, idempotency_key, participant_role FROM maa_slug_operation_bindings WHERE operation_id = :operation_id ORDER BY participant_role ASC, binding_id ASC');
        if ($statement === false) {
            throw new SlugPersistenceInvariantException('Unable to prepare the operation participant read.');
        }
        $statement->execute(['operation_id' => $operationId]);
        $rows = PdoRowHydrator::many($statement->fetchAll(PDO::FETCH_ASSOC));

        return array_map(static fn(array $row): OperationParticipant => new OperationParticipant(
            PdoRowHydrator::nonNegativeInt($row, 'binding_id'),
            PdoRowHydrator::string($row, 'idempotency_key'),
            PdoRowHydrator::string($row, 'participant_role'),
        ), $rows);
    }

    /** @param array<string, mixed> $row */
    private function operationFromRow(array $row): OperationRecord
    {
        try {
            $operationType = OperationTypeEnum::tryFrom(PdoRowHydrator::string($row, 'operation_type'));
            if ($operationType === null) {
                throw new SlugPersistenceInvariantException('Operation row contains an unknown operation type.');
            }
            $this->assertResultOperationCompatibility(PdoRowHydrator::string($row, 'result_type'), $operationType);

            return new OperationRecord(
                $this->nonNegativeInt($row['id'], 'operation.id'),
                PdoRowHydrator::string($row, 'operation_key'),
                $operationType,
                PdoRowHydrator::string($row, 'request_fingerprint'),
                PdoRowHydrator::string($row, 'result_type'),
                $this->nonNegativeInt($row['result_schema_version'], 'operation.result_schema_version'),
                PdoRowHydrator::nullableString($row, 'result_snapshot'),
                PdoRowHydrator::string($row, 'status'),
                $this->date(PdoRowHydrator::string($row, 'created_at'), 'operation.created_at'),
                PdoRowHydrator::nullableString($row, 'completed_at') === null ? null : $this->date(PdoRowHydrator::string($row, 'completed_at'), 'operation.completed_at'),
            );
        } catch (SlugPersistenceInvariantException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            throw new SlugPersistenceInvariantException('Operation row violates the persistence invariant.', 0, $throwable);
        }
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function date(mixed $value, string $field): DateTimeImmutable
    {
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}\z/', $value) !== 1) {
            throw new SlugPersistenceInvariantException(sprintf('%s must contain six microseconds.', $field));
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new SlugPersistenceInvariantException(sprintf('%s is not a valid UTC timestamp.', $field));
        }

        return $date;
    }

    private function nonNegativeInt(mixed $value, string $field): int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/\A\d+\z/', $value) === 1) {
            $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($integer === false) {
                throw new SlugPersistenceInvariantException(sprintf('%s exceeds PHP integer range.', $field));
            }
        } else {
            throw new SlugPersistenceInvariantException(sprintf('%s must be a non-negative integer.', $field));
        }
        if ($integer < 0) {
            throw new SlugPersistenceInvariantException(sprintf('%s must be non-negative.', $field));
        }

        return $integer;
    }
}
