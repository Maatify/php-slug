<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Contracts;

use Maatify\Slug\Contract\ResultSnapshot\ResultSnapshotDecoder;
use Maatify\Slug\Contract\ResultSnapshot\ResultSnapshotEncoder;
use Maatify\Slug\DTO\AdoptionResultDTO;
use Maatify\Slug\DTO\AtomicTransferResultDTO;
use Maatify\Slug\DTO\ScopeTransitionResultDTO;
use Maatify\Slug\DTO\SlugMutationResultDTO;
use Maatify\Slug\Exception\SlugPersistenceInvariantException;
use PHPUnit\Framework\TestCase;

final class ResultSnapshotTest extends TestCase
{
    /** @return iterable<string, array{0: AdoptionResultDTO|AtomicTransferResultDTO|ScopeTransitionResultDTO|SlugMutationResultDTO, 1: string, 2: list<string>}> */
    public static function resultCases(): iterable
    {
        yield 'mutation' => [ContractFixtures::mutation(), 'mutation', [
            'operation_type', 'operation_key', 'replayed', 'before', 'after', 'affected_claims',
            'previous_slug', 'current_slug', 'change_type', 'revision', 'history_events',
        ]];
        yield 'transition' => [ContractFixtures::transition(), 'transition', [
            'operation_type', 'operation_key', 'replayed', 'mode', 'target_created', 'source_result',
            'target_result', 'source_before', 'source_after', 'target_before', 'target_after',
            'source_claim', 'target_claim', 'source_revision', 'target_revision', 'history_events',
        ]];
        yield 'transfer' => [ContractFixtures::transfer(), 'transfer', [
            'operation_type', 'operation_key', 'replayed', 'source_result', 'target_result',
            'transferred_claim', 'source_replacement_result', 'source_revision', 'target_revision', 'history_events',
        ]];
        yield 'adoption' => [ContractFixtures::adoption(), 'adoption', [
            'operation_type', 'operation_key', 'replayed', 'before', 'after', 'adopted_claim', 'history_event',
        ]];
    }

    /** @param list<string> $resultKeys */
    #[\PHPUnit\Framework\Attributes\DataProvider('resultCases')]
    public function testCanonicalV1ShapeAndReplay(AdoptionResultDTO|AtomicTransferResultDTO|ScopeTransitionResultDTO|SlugMutationResultDTO $result, string $resultType, array $resultKeys): void
    {
        $json = ResultSnapshotEncoder::encode($result);
        $decodedJson = $this->decodeObject($json);
        $decodedResult = $decodedJson['result'] ?? null;
        if (! is_array($decodedResult)) {
            self::fail('Snapshot result must be an object.');
        }

        self::assertSame(['result_type', 'result_schema_version', 'result'], array_keys($decodedJson));
        self::assertSame($resultType, $decodedJson['result_type']);
        self::assertSame(1, $decodedJson['result_schema_version']);
        self::assertSame($resultKeys, array_keys($decodedResult));
        self::assertFalse($result->replayed);

        $replayed = ResultSnapshotDecoder::decode($json, ContractFixtures::registry());

        self::assertTrue($replayed->replayed);
        self::assertSame($json, ResultSnapshotEncoder::encode($replayed->withReplayed(false)));
        self::assertFalse($result->replayed);
    }

    public function testDecoderRejectsMissingExtraUnknownWrongTypeDuplicateAndReplayFields(): void
    {
        $json = ResultSnapshotEncoder::encode(ContractFixtures::mutation());
        $decoded = $this->decodeObject($json);
        if (! is_array($decoded['result'] ?? null)) {
            self::fail('Snapshot result must be an object.');
        }

        $cases = [];
        $missing = $decoded;
        unset($missing['result']['revision']);
        $cases['missing'] = json_encode($missing, JSON_THROW_ON_ERROR);
        $extra = $decoded;
        $extra['result']['unknown'] = true;
        $cases['extra'] = json_encode($extra, JSON_THROW_ON_ERROR);
        $wrongType = $decoded;
        $wrongType['result']['revision'] = '1';
        $cases['wrong type'] = json_encode($wrongType, JSON_THROW_ON_ERROR);
        $replayed = $decoded;
        $replayed['result']['replayed'] = true;
        $cases['replayed'] = json_encode($replayed, JSON_THROW_ON_ERROR);
        $cases['duplicate'] = '{"result_type":"mutation","result_type":"mutation","result_schema_version":1,"result":{}}';

        foreach ($cases as $name => $payload) {
            $this->decodeMustFail($payload, $name);
        }
    }

    public function testDecoderHonorsStorageDiscriminators(): void
    {
        $json = ResultSnapshotEncoder::encode(ContractFixtures::mutation());

        $this->expectException(SlugPersistenceInvariantException::class);
        ResultSnapshotDecoder::decode($json, ContractFixtures::registry(), 'adoption', 1, 'ASSIGN_EXACT');
    }

    /** @return array<string, mixed> */
    private function decodeObject(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || array_is_list($decoded)) {
            self::fail('Expected a JSON object.');
        }
        $object = [];
        foreach ($decoded as $key => $value) {
            if (! is_string($key)) {
                self::fail('Expected string JSON object keys.');
            }
            $object[$key] = $value;
        }
        return $object;
    }

    private function decodeMustFail(string $payload, string $name): void
    {
        try {
            ResultSnapshotDecoder::decode($payload, ContractFixtures::registry());
        } catch (SlugPersistenceInvariantException $exception) {
            self::assertInstanceOf(SlugPersistenceInvariantException::class, $exception);
            return;
        }
        self::fail(sprintf('Case %s was accepted.', $name));
    }
}
