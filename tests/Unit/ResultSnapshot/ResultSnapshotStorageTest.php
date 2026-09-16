<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\ResultSnapshot;

use Maatify\Slug\Contract\ResultSnapshot\ResultSnapshotEncoder;
use Maatify\Slug\DTO\HistoryEventDTO;
use Maatify\Slug\DTO\SlugMutationResultDTO;
use Maatify\Slug\Exception\SlugPersistenceInvariantException;
use Maatify\Slug\Internal\ResultSnapshot\ResultSnapshotMetadata;
use Maatify\Slug\Internal\ResultSnapshot\ResultSnapshotStorage;
use Maatify\Slug\Tests\Unit\Contracts\ContractFixtures;
use PHPUnit\Framework\TestCase;

final class ResultSnapshotStorageTest extends TestCase
{
    public function testAllFourResultTypesRoundTripThroughCanonicalStorageValidation(): void
    {
        foreach ([
            ['mutation', ContractFixtures::mutation()],
            ['transition', ContractFixtures::transition()],
            ['transfer', ContractFixtures::transfer()],
            ['adoption', ContractFixtures::adoption()],
        ] as $index => [$resultType, $fixture]) {
            $operationKey = str_repeat((string) ($index + 1), 32);
            $snapshot = ResultSnapshotEncoder::encode($fixture);
            $snapshot = str_replace('"operation_key":null', '"operation_key":"' . $operationKey . '"', $snapshot);
            $metadata = new ResultSnapshotMetadata($resultType, 1, $fixture->operationType, $operationKey, $snapshot);
            $decoded = ResultSnapshotStorage::decodeCommitted($metadata, ContractFixtures::registry());

            self::assertTrue($decoded->replayed, $resultType);
            self::assertSame($snapshot, ResultSnapshotEncoder::encode($decoded->withReplayed(false)), $resultType);
        }
    }

    public function testCanonicalStoredSnapshotDecodesAsReplayOnlyInMemory(): void
    {
        $operationKey = str_repeat('a', 32);
        $fixture = ContractFixtures::mutation();
        $event = $fixture->historyEvents[0];
        $history = [new HistoryEventDTO(
            $event->id,
            $event->bindingId,
            $event->sequenceNo,
            $event->eventType,
            $event->scopeSnapshot,
            $event->entitySnapshot,
            $event->slugSnapshot,
            $event->previousSlugSnapshot,
            $event->claimRoleSnapshot,
            $event->previousClaimRoleSnapshot,
            $event->relatedScope,
            $event->relatedEntity,
            $operationKey,
            $event->actorKey,
            $event->reason,
            $event->correlationKey,
            $event->occurredAt,
            $event->originalOccurredAt,
        )];
        $result = new SlugMutationResultDTO(
            $fixture->operationType,
            $operationKey,
            false,
            $fixture->before,
            $fixture->after,
            $fixture->affectedClaims,
            $fixture->previousSlug,
            $fixture->currentSlug,
            $fixture->changeType,
            $fixture->revision,
            $history,
        );
        $snapshot = ResultSnapshotEncoder::encode($result);
        $metadata = new ResultSnapshotMetadata('mutation', 1, $result->operationType, $operationKey, $snapshot);

        $decoded = ResultSnapshotStorage::decodeCommitted($metadata, ContractFixtures::registry());

        self::assertTrue($decoded->replayed);
        self::assertSame($operationKey, $decoded->operationKey);
        self::assertSame($snapshot, ResultSnapshotEncoder::encode($decoded->withReplayed(false)));
    }

    public function testNonCanonicalOrMalformedStoredSnapshotIsRejected(): void
    {
        $operationKey = str_repeat('b', 32);
        $fixture = ContractFixtures::mutation();
        $event = $fixture->historyEvents[0];
        $history = [new HistoryEventDTO(
            $event->id,
            $event->bindingId,
            $event->sequenceNo,
            $event->eventType,
            $event->scopeSnapshot,
            $event->entitySnapshot,
            $event->slugSnapshot,
            $event->previousSlugSnapshot,
            $event->claimRoleSnapshot,
            $event->previousClaimRoleSnapshot,
            $event->relatedScope,
            $event->relatedEntity,
            $operationKey,
            $event->actorKey,
            $event->reason,
            $event->correlationKey,
            $event->occurredAt,
            $event->originalOccurredAt,
        )];
        $result = new SlugMutationResultDTO($fixture->operationType, $operationKey, false, $fixture->before, $fixture->after, $fixture->affectedClaims, $fixture->previousSlug, $fixture->currentSlug, $fixture->changeType, $fixture->revision, $history);
        $snapshot = ResultSnapshotEncoder::encode($result);
        $malformed = substr_replace($snapshot, ',"extra":true}', -1, 0);

        $this->expectException(SlugPersistenceInvariantException::class);
        ResultSnapshotStorage::decodeCommitted(
            new ResultSnapshotMetadata('mutation', 1, $result->operationType, $operationKey, $malformed),
            ContractFixtures::registry(),
        );
    }
}
