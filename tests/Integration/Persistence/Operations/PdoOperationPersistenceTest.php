<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Integration\Persistence\Operations;

use Maatify\Slug\Scope\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\DTO\AdoptionResultDTO;
use Maatify\Slug\Lifecycle\ResultSnapshot\ResultSnapshotEncoder;
use Maatify\Slug\Registry\Value\EntityReference;
use Maatify\Slug\Profile\Value\SlugProfileKey;
use Maatify\Slug\Persistence\PDO\Connection\PdoCapabilityGuard;
use Maatify\Slug\Persistence\PDO\Operations\PdoOperationRepository;
use Maatify\Slug\Persistence\Contract\OperationParticipant;
use Maatify\Slug\Persistence\PDO\Scope\PdoScopeRepository;
use Maatify\Slug\Profile\Registry\SlugProfileRegistry;
use Maatify\Slug\Lifecycle\Enum\OperationTypeEnum;
use Maatify\Slug\Exception\SlugIdempotencyConflictException;
use Maatify\Slug\Exception\SlugTransactionParticipationException;
use Maatify\Slug\Lifecycle\ResultSnapshot\ResultSnapshotMetadata;
use Maatify\Slug\Scope\Value\SlugScope;
use Maatify\Slug\Tests\Integration\Schema\MySqlIntegrationTestCase;
use Maatify\Slug\Tests\Unit\Contracts\ContractFixtures;
use Maatify\Slug\Tests\Unit\Profile\StubSlugProfile;

final class PdoOperationPersistenceTest extends MySqlIntegrationTestCase
{
    public function testOperationParticipantReservationAndCommittedSnapshotRoundTrip(): void
    {
        $profile = new StubSlugProfile(new SlugProfileKey('ascii-v1'));
        $profiles = new SlugProfileRegistry();
        $profiles->register($profile);
        $capabilities = new PdoCapabilityGuard($this->pdo);
        $scopeRepository = new PdoScopeRepository($this->pdo, $profiles, $this->clock(), $capabilities);
        $request = new ScopeProfileRequestDTO(new SlugScope('catalog', null, null), new SlugProfileKey('ascii-v1'));
        $binding = $scopeRepository->ensureBindingPlaceholder($request, new EntityReference('product', '42'));
        $operationKey = str_repeat('a', 32);
        $fingerprint = str_repeat('b', 64);
        $operationRepository = new PdoOperationRepository($this->pdo, $this->clock(), $capabilities);

        self::assertTrue($this->pdo->beginTransaction());
        try {
            $reservation = $operationRepository->reserve(
                $operationKey,
                OperationTypeEnum::ASSIGN_EXACT,
                $fingerprint,
                'mutation',
                1,
                [new OperationParticipant($binding->id, 'request-42', 'SINGLE')],
            );

            self::assertTrue($reservation->created);
            $fixture = ContractFixtures::mutation();
            $snapshot = \Maatify\Slug\Lifecycle\ResultSnapshot\ResultSnapshotEncoder::encode($fixture);
            $snapshot = str_replace('"operation_key":null', '"operation_key":"' . $operationKey . '"', $snapshot);
            $metadata = new ResultSnapshotMetadata('mutation', 1, OperationTypeEnum::ASSIGN_EXACT, $operationKey, $snapshot);
            $operationRepository->commitSnapshot($reservation->operation->id, $metadata, $profiles);

            $stored = $operationRepository->findByParticipant($binding->id, 'request-42');
            self::assertNotNull($stored);
            self::assertSame('COMMITTED', $stored->status);
            self::assertNotNull($stored->resultSnapshot);
            self::assertSame($operationKey, $stored->operationKey);
            self::assertTrue($this->pdo->commit());
        } catch (\Throwable $throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $throwable;
        }
    }

    public function testOperationReservationCannotBeUsedWithoutCallerTransaction(): void
    {
        $profiles = $this->profiles();
        $scopeRepository = new PdoScopeRepository($this->pdo, $profiles, $this->clock(), new PdoCapabilityGuard($this->pdo));
        $binding = $scopeRepository->ensureBindingPlaceholder(
            new ScopeProfileRequestDTO(new SlugScope('catalog', null, null), new SlugProfileKey('ascii-v1')),
            new EntityReference('product', 'transaction-required'),
        );
        $repository = new PdoOperationRepository($this->pdo, $this->clock(), new PdoCapabilityGuard($this->pdo));

        $this->expectException(SlugTransactionParticipationException::class);
        try {
            $repository->reserve(
                str_repeat('a', 32),
                OperationTypeEnum::ASSIGN_EXACT,
                str_repeat('b', 64),
                'mutation',
                1,
                [new OperationParticipant($binding->id, 'transaction-required', 'SINGLE')],
            );
        } finally {
            $statement = $this->pdo->query("SELECT COUNT(*) FROM maa_slug_operations WHERE status = 'IN_PROGRESS'");
            self::assertNotFalse($statement);
            self::assertSame('0', (string) $statement->fetchColumn());
        }
    }

    public function testReservationAndSnapshotAreInvisibleUntilFinalCallerCommit(): void
    {
        $profiles = $this->profiles();
        $scopeRepository = new PdoScopeRepository($this->pdo, $profiles, $this->clock(), new PdoCapabilityGuard($this->pdo));
        $binding = $scopeRepository->ensureBindingPlaceholder(
            new ScopeProfileRequestDTO(new SlugScope('catalog', null, null), new SlugProfileKey('ascii-v1')),
            new EntityReference('product', 'visibility'),
        );
        $repository = new PdoOperationRepository($this->pdo, $this->clock(), new PdoCapabilityGuard($this->pdo));
        $independent = $this->newTestConnection();
        $operationKey = str_repeat('a', 32);

        self::assertTrue($this->pdo->beginTransaction());
        try {
            $reservation = $repository->reserve(
                $operationKey,
                OperationTypeEnum::ASSIGN_EXACT,
                str_repeat('b', 64),
                'mutation',
                1,
                [new OperationParticipant($binding->id, 'visibility', 'SINGLE')],
            );
            self::assertSame('0', $this->countRows($independent, "SELECT COUNT(*) FROM maa_slug_operations WHERE status = 'IN_PROGRESS'"));

            $snapshot = $this->snapshotWithOperationKey(ResultSnapshotEncoder::encode(ContractFixtures::mutation()), $operationKey);
            $repository->commitSnapshot(
                $reservation->operation->id,
                new ResultSnapshotMetadata('mutation', 1, OperationTypeEnum::ASSIGN_EXACT, $operationKey, $snapshot),
                $profiles,
            );
            self::assertSame('0', $this->countRows($independent, 'SELECT COUNT(*) FROM maa_slug_operations'));
            self::assertTrue($this->pdo->commit());
        } catch (\Throwable $throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $throwable;
        }

        self::assertSame('0', $this->countRows($independent, "SELECT COUNT(*) FROM maa_slug_operations WHERE status = 'IN_PROGRESS'"));
        self::assertSame('1', $this->countRows($independent, "SELECT COUNT(*) FROM maa_slug_operations WHERE status = 'COMMITTED'"));
    }

    public function testFailureAfterReservationRollsBackOperationAndParticipants(): void
    {
        $profiles = $this->profiles();
        $scopeRepository = new PdoScopeRepository($this->pdo, $profiles, $this->clock(), new PdoCapabilityGuard($this->pdo));
        $binding = $scopeRepository->ensureBindingPlaceholder(
            new ScopeProfileRequestDTO(new SlugScope('catalog', null, null), new SlugProfileKey('ascii-v1')),
            new EntityReference('product', 'failed-operation'),
        );
        $repository = new PdoOperationRepository($this->pdo, $this->clock(), new PdoCapabilityGuard($this->pdo));
        self::assertTrue($this->pdo->beginTransaction());
        try {
            $repository->reserve(
                str_repeat('c', 32),
                OperationTypeEnum::ASSIGN_EXACT,
                str_repeat('d', 64),
                'mutation',
                1,
                [new OperationParticipant($binding->id, 'failed-operation', 'SINGLE')],
            );
            throw new \RuntimeException('forced lifecycle failure after reservation');
        } catch (\RuntimeException $throwable) {
            self::assertSame('forced lifecycle failure after reservation', $throwable->getMessage());
            self::assertTrue($this->pdo->rollBack());
        }

        $independent = $this->newTestConnection();
        self::assertSame('0', $this->countRows($independent, 'SELECT COUNT(*) FROM maa_slug_operations'));
        self::assertSame('0', $this->countRows($independent, 'SELECT COUNT(*) FROM maa_slug_operation_bindings'));
    }

    public function testAllFourResultTypesRoundTripThroughRealDatabaseStorageWithMicroseconds(): void
    {
        $profiles = $this->profiles();
        $scopeRepository = new PdoScopeRepository($this->pdo, $profiles, $this->clock(), new PdoCapabilityGuard($this->pdo));
        $operationRepository = new PdoOperationRepository($this->pdo, $this->clock(), new PdoCapabilityGuard($this->pdo));

        $source = $scopeRepository->ensureBindingPlaceholder(
            new ScopeProfileRequestDTO(new SlugScope('catalog', null, null), new SlugProfileKey('ascii-v1')),
            new EntityReference('product', 'snapshot-source'),
        );
        $target = $scopeRepository->ensureBindingPlaceholder(
            new ScopeProfileRequestDTO(new SlugScope('store', null, null), new SlugProfileKey('ascii-v1')),
            new EntityReference('product', 'snapshot-target'),
        );
        $definitions = [
            ['mutation', ContractFixtures::mutation(), [new OperationParticipant($source->id, 'snapshot-mutation', 'SINGLE')]],
            ['transition', ContractFixtures::transition(), [
                new OperationParticipant($source->id, 'snapshot-transition', 'SOURCE'),
                new OperationParticipant($target->id, 'snapshot-transition', 'TARGET'),
            ]],
            ['transfer', ContractFixtures::transfer(), [
                new OperationParticipant($source->id, 'snapshot-transfer', 'SOURCE'),
                new OperationParticipant($target->id, 'snapshot-transfer', 'TARGET'),
            ]],
            ['adoption', ContractFixtures::adoption(), [new OperationParticipant($source->id, 'snapshot-adoption', 'SINGLE')]],
        ];

        foreach ($definitions as $index => [$resultType, $fixture, $participants]) {
            $operationKey = str_repeat((string) ($index + 1), 32);
            $snapshot = $this->snapshotWithOperationKey(ResultSnapshotEncoder::encode($fixture), $operationKey);
            self::assertTrue($this->pdo->beginTransaction());
            try {
                $reservation = $operationRepository->reserve(
                    $operationKey,
                    $fixture->operationType,
                    str_repeat((string) ($index + 5), 64),
                    $resultType,
                    1,
                    $participants,
                );
                self::assertTrue($reservation->created, $resultType);

                $operationRepository->commitSnapshot(
                    $reservation->operation->id,
                    new ResultSnapshotMetadata($resultType, 1, $fixture->operationType, $operationKey, $snapshot),
                    $profiles,
                );
                self::assertTrue($this->pdo->commit());
            } catch (\Throwable $throwable) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $throwable;
            }
            $replayed = $operationRepository->decodeCommitted($reservation->operation->id, $profiles);
            self::assertTrue($replayed->replayed, $resultType);
            self::assertSame($snapshot, ResultSnapshotEncoder::encode($replayed->withReplayed(false)), $resultType);
            $occurredAt = $replayed instanceof AdoptionResultDTO
                ? $replayed->historyEvent->occurredAt
                : $replayed->historyEvents[0]->occurredAt;
            self::assertSame('123456', $occurredAt->format('u'), $resultType);
        }
    }

    public function testCommittedReservationReplaysAndConflictingEvidenceIsRejected(): void
    {
        $profiles = $this->profiles();
        $scopeRepository = new PdoScopeRepository($this->pdo, $profiles, $this->clock(), new PdoCapabilityGuard($this->pdo));
        $binding = $scopeRepository->ensureBindingPlaceholder(
            new ScopeProfileRequestDTO(new SlugScope('catalog', null, null), new SlugProfileKey('ascii-v1')),
            new EntityReference('product', 'replay'),
        );
        $repository = new PdoOperationRepository($this->pdo, $this->clock(), new PdoCapabilityGuard($this->pdo));
        $operationKey = str_repeat('a', 32);
        $fingerprint = str_repeat('b', 64);
        $participant = new OperationParticipant($binding->id, 'replay-key', 'SINGLE');
        self::assertTrue($this->pdo->beginTransaction());
        try {
            $reservation = $repository->reserve($operationKey, OperationTypeEnum::ASSIGN_EXACT, $fingerprint, 'mutation', 1, [$participant]);
            $snapshot = $this->snapshotWithOperationKey(ResultSnapshotEncoder::encode(ContractFixtures::mutation()), $operationKey);
            $repository->commitSnapshot($reservation->operation->id, new ResultSnapshotMetadata('mutation', 1, OperationTypeEnum::ASSIGN_EXACT, $operationKey, $snapshot), $profiles);
            self::assertTrue($this->pdo->commit());
        } catch (\Throwable $throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $throwable;
        }

        self::assertTrue($this->pdo->beginTransaction());
        try {
            $replay = $repository->reserve($operationKey, OperationTypeEnum::ASSIGN_EXACT, $fingerprint, 'mutation', 1, [$participant]);
            self::assertFalse($replay->created);
            self::assertTrue($replay->isReplay());
            self::assertTrue($this->pdo->commit());
        } catch (\Throwable $throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $throwable;
        }

        $otherBinding = $scopeRepository->ensureBindingPlaceholder(
            new ScopeProfileRequestDTO(new SlugScope('catalog', null, null), new SlugProfileKey('ascii-v1')),
            new EntityReference('product', 'replay-other'),
        );
        self::assertTrue($this->pdo->beginTransaction());
        try {
            $repository->reserve(
                $operationKey,
                OperationTypeEnum::ASSIGN_EXACT,
                $fingerprint,
                'mutation',
                1,
                [new OperationParticipant($otherBinding->id, 'replay-other-key', 'SINGLE')],
            );
            self::fail('A participant mismatch must be an idempotency conflict.');
        } catch (SlugIdempotencyConflictException) {
            $statement = $this->pdo->query("SELECT COUNT(*) FROM maa_slug_operations WHERE status = 'IN_PROGRESS'");
            self::assertNotFalse($statement);
            self::assertSame('0', (string) $statement->fetchColumn());
            self::assertTrue($this->pdo->rollBack());
        }

        self::assertTrue($this->pdo->beginTransaction());
        try {
            $repository->reserve($operationKey, OperationTypeEnum::ASSIGN_EXACT, str_repeat('c', 64), 'mutation', 1, [$participant]);
            self::fail('A fingerprint mismatch must be an idempotency conflict.');
        } catch (SlugIdempotencyConflictException) {
            self::assertTrue($this->pdo->rollBack());
        }
    }

    public function testParticipantRaceDoesNotLeaveAnOrphanInProgressOperation(): void
    {
        if (! function_exists('pcntl_fork')) {
            self::fail('WU-03 concurrency evidence requires pcntl_fork.');
        }

        $profiles = $this->profiles();
        $scopeRepository = new PdoScopeRepository($this->pdo, $profiles, $this->clock(), new PdoCapabilityGuard($this->pdo));
        $binding = $scopeRepository->ensureBindingPlaceholder(
            new ScopeProfileRequestDTO(new SlugScope('catalog', null, null), new SlugProfileKey('ascii-v1')),
            new EntityReference('product', 'race'),
        );
        $participantKey = 'race-idempotency';
        $winnerOperationKey = str_repeat('d', 32);
        $loserOperationKey = str_repeat('e', 32);
        $snapshot = $this->snapshotWithOperationKey(ResultSnapshotEncoder::encode(ContractFixtures::mutation()), $winnerOperationKey);
        $resultFile = tempnam(sys_get_temp_dir(), 'slug-wu03-race-');
        $readyFile = tempnam(sys_get_temp_dir(), 'slug-wu03-ready-');
        $releaseFile = tempnam(sys_get_temp_dir(), 'slug-wu03-release-');
        self::assertNotFalse($resultFile);
        self::assertNotFalse($readyFile);
        self::assertNotFalse($releaseFile);
        unlink($resultFile);
        unlink($readyFile);
        unlink($releaseFile);

        $childPid = pcntl_fork();
        self::assertNotSame(-1, $childPid);
        if ($childPid === 0) {
            try {
                $childPdo = $this->newTestConnection();
                $childPdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
                $childRepository = new PdoOperationRepository(
                    $childPdo,
                    $this->clock(),
                    new PdoCapabilityGuard($childPdo),
                    null,
                    static function () use ($readyFile, $releaseFile): void {
                        file_put_contents($readyFile, 'ready');
                        for ($attempt = 0; $attempt < 1000 && ! is_file($releaseFile); $attempt++) {
                            usleep(10000);
                        }
                    },
                );
                self::assertTrue($childPdo->beginTransaction());
                $childRepository->reserve(
                    $loserOperationKey,
                    OperationTypeEnum::ASSIGN_EXACT,
                    str_repeat('f', 64),
                    'mutation',
                    1,
                    [new OperationParticipant($binding->id, $participantKey, 'SINGLE')],
                );
                file_put_contents($resultFile, json_encode(['class' => null], JSON_THROW_ON_ERROR));
            } catch (\Throwable $throwable) {
                if (isset($childPdo) && $childPdo->inTransaction()) {
                    $childPdo->rollBack();
                }
                file_put_contents($resultFile, json_encode(['class' => $throwable::class], JSON_THROW_ON_ERROR));
            }
            if (isset($childPdo) && $childPdo->inTransaction()) {
                $childPdo->rollBack();
            }
            exit(0);
        }

        // PDO connections must not be reused across fork boundaries.
        $this->pdo = $this->newTestConnection();
        try {
            for ($attempt = 0; $attempt < 500 && ! is_file($readyFile); $attempt++) {
                usleep(10000);
            }
            self::assertFileExists($readyFile);
            self::assertTrue($this->pdo->beginTransaction());
            $insertOperation = $this->pdo->prepare(
                'INSERT INTO maa_slug_operations (operation_key, operation_type, request_fingerprint, result_type, result_schema_version, result_snapshot, status, created_at, completed_at) '
                . 'VALUES (:operation_key, :operation_type, :request_fingerprint, :result_type, 1, :result_snapshot, \'COMMITTED\', :created_at, :completed_at)',
            );
            self::assertNotFalse($insertOperation);
            $insertOperation->execute([
                'operation_key' => $winnerOperationKey,
                'operation_type' => OperationTypeEnum::ASSIGN_EXACT->value,
                'request_fingerprint' => str_repeat('1', 64),
                'result_type' => 'mutation',
                'result_snapshot' => $snapshot,
                'created_at' => '2026-01-01 00:00:00.123456',
                'completed_at' => '2026-01-01 00:00:00.123456',
            ]);
            $operationId = (int) $this->pdo->lastInsertId();
            $insertParticipant = $this->pdo->prepare(
                'INSERT INTO maa_slug_operation_bindings (idempotency_key, operation_id, binding_id, participant_role) VALUES (:idempotency_key, :operation_id, :binding_id, \'SINGLE\')',
            );
            self::assertNotFalse($insertParticipant);
            $insertParticipant->execute(['idempotency_key' => $participantKey, 'operation_id' => $operationId, 'binding_id' => $binding->id]);
            $this->pdo->commit();
            file_put_contents($releaseFile, 'release');
        } catch (\Throwable $throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            posix_kill($childPid, SIGTERM);
            pcntl_waitpid($childPid, $childStatus);
            foreach ([$resultFile, $readyFile, $releaseFile] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            throw $throwable;
        }

        pcntl_waitpid($childPid, $childStatus);
        $payload = json_decode((string) file_get_contents($resultFile), true, 512, JSON_THROW_ON_ERROR);
        foreach ([$resultFile, $readyFile, $releaseFile] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        self::assertIsArray($payload);
        self::assertArrayHasKey('class', $payload);
        self::assertSame(SlugIdempotencyConflictException::class, $payload['class']);

        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM maa_slug_operations WHERE operation_key = :operation_key');
        self::assertNotFalse($statement);
        $statement->execute(['operation_key' => $loserOperationKey]);
        self::assertSame('0', (string) $statement->fetchColumn());
        $statement = $this->pdo->query("SELECT COUNT(*) FROM maa_slug_operations WHERE status = 'IN_PROGRESS'");
        self::assertNotFalse($statement);
        self::assertSame('0', (string) $statement->fetchColumn());
    }

    private function profiles(): SlugProfileRegistry
    {
        $profiles = new SlugProfileRegistry();
        $profiles->register(new StubSlugProfile(new SlugProfileKey('ascii-v1')));

        return $profiles;
    }

    private function snapshotWithOperationKey(string $snapshot, string $operationKey): string
    {
        return str_replace('"operation_key":null', '"operation_key":"' . $operationKey . '"', $snapshot);
    }

    private function countRows(\PDO $pdo, string $sql): string
    {
        $statement = $pdo->query($sql);
        self::assertNotFalse($statement);

        return (string) $statement->fetchColumn();
    }
}
