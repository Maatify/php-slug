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

    public function testDecoderRejectsAllRequiredStorageAndTransferInvariantFailures(): void
    {
        $mutation = $this->decodeObject(ResultSnapshotEncoder::encode(ContractFixtures::mutation()));
        $mutationResult = $this->objectValue($mutation['result'], 'mutation result');

        $cases = [];
        $unknownType = $mutation;
        $unknownType['result_type'] = 'unknown';
        $cases['unknown result type'] = [$this->encodeObject($unknownType), null, null, null, null];
        $unknownVersion = $mutation;
        $unknownVersion['result_schema_version'] = 2;
        $cases['unknown schema version'] = [$this->encodeObject($unknownVersion), null, null, null, null];
        $wrongEnum = $this->withResultField($mutation, 'change_type', 'UNKNOWN');
        $cases['wrong enum token'] = [$this->encodeObject($wrongEnum), null, null, null, null];
        $badTimestampResult = $this->withNestedObjectField($mutationResult, 'after', 'created_at', '0999-12-31T23:59:59.999999Z');
        $badTimestamp = $this->withResultField($mutation, 'after', $badTimestampResult['after']);
        $cases['out of range timestamp'] = [$this->encodeObject($badTimestamp), null, null, null, null];
        $wrongList = $this->withResultField($mutation, 'affected_claims', ['claim' => []]);
        $cases['wrong list type'] = [$this->encodeObject($wrongList), null, null, null, null];
        $claims = $this->listValue($mutationResult['affected_claims'], 'affected claims');
        $claim = $this->objectValue($claims[0], 'affected claim');
        $claim['id'] = 199;
        $outOfOrder = $this->withResultField($mutation, 'affected_claims', [$claims[0], $claim]);
        $cases['out of order list'] = [$this->encodeObject($outOfOrder), null, null, null, null];
        $incompatible = $this->withResultField($mutation, 'operation_type', 'TRANSITION_SCOPE');
        $cases['incompatible operation type'] = [$this->encodeObject($incompatible), null, null, null, null];
        $cases['storage result type mismatch'] = [ResultSnapshotEncoder::encode(ContractFixtures::mutation()), 'adoption', null, null, null];
        $cases['storage schema version mismatch'] = [ResultSnapshotEncoder::encode(ContractFixtures::mutation()), null, 2, null, null];
        $cases['storage operation type mismatch'] = [ResultSnapshotEncoder::encode(ContractFixtures::mutation()), null, null, 'CHANGE_EXACT', null];
        $cases['storage operation key mismatch'] = [ResultSnapshotEncoder::encode(ContractFixtures::mutation()), null, null, null, str_repeat('a', 32)];

        $transfer = $this->decodeObject(ResultSnapshotEncoder::encode(ContractFixtures::transfer()));
        $transferResult = $this->objectValue($transfer['result'], 'transfer result');
        $replacement = $this->objectValue($transferResult['source_replacement_result'] ?? null, 'source replacement result');
        $noReplacement = $this->withResultField($transfer, 'source_replacement_result', null);
        $cases['invalid current replacement presence'] = [$this->encodeObject($noReplacement), null, null, null, null];

        $badNestedOperation = $this->withNestedResultField($transfer, 'source_replacement_result', $this->withObjectField($replacement, 'operation_type', 'CHANGE_EXACT'));
        $cases['invalid nested operation type'] = [$this->encodeObject($badNestedOperation), null, null, null, null];

        $outerNestedKeyMismatch = $this->withResultField($transfer, 'operation_key', str_repeat('a', 32));
        $outerNestedKeyMismatch = $this->withNestedResultField($outerNestedKeyMismatch, 'source_replacement_result', $this->withObjectField($replacement, 'operation_key', str_repeat('b', 32)));
        $cases['outer nested operation key mismatch'] = [$this->encodeObject($outerNestedKeyMismatch), null, null, null, null];

        $outerNestedReplayMismatch = $this->withNestedResultField($transfer, 'source_replacement_result', $this->withObjectField($replacement, 'replayed', true));
        $cases['outer nested replay mismatch'] = [$this->encodeObject($outerNestedReplayMismatch), null, null, null, null];

        $badChangeType = $this->withNestedResultField($transfer, 'source_replacement_result', $this->withObjectField($replacement, 'change_type', 'ASSIGNED'));
        $cases['invalid replacement change type'] = [$this->encodeObject($badChangeType), null, null, null, null];

        $replacementAfter = $this->objectValue($replacement['after'] ?? null, 'replacement after');
        $replacementAfterState = $this->objectValue($replacementAfter['state'] ?? null, 'replacement after state');
        $replacementAfterState['status'] = 'RELEASED';
        $replacementAfterState['current_slug'] = null;
        $replacementAfter['state'] = $replacementAfterState;
        $replacementAfter['current_claim'] = null;
        $transientReplacement = $this->withObjectField($replacement, 'after', $replacementAfter);
        $transient = $this->withNestedResultField($transfer, 'source_replacement_result', $transientReplacement);
        $cases['transient source replacement state'] = [$this->encodeObject($transient), null, null, null, null];

        foreach ($cases as $name => [$payload, $resultType, $schemaVersion, $operationType, $operationKey]) {
            $this->decodeMustFail($payload, $name, $resultType, $schemaVersion, $operationType, $operationKey);
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

    private function decodeMustFail(
        string $payload,
        string $name,
        ?string $expectedResultType = null,
        ?int $expectedSchemaVersion = null,
        ?string $expectedOperationType = null,
        ?string $expectedOperationKey = null,
    ): void
    {
        try {
            ResultSnapshotDecoder::decode(
                $payload,
                ContractFixtures::registry(),
                $expectedResultType,
                $expectedSchemaVersion,
                $expectedOperationType,
                $expectedOperationKey,
            );
        } catch (SlugPersistenceInvariantException $exception) {
            self::assertInstanceOf(SlugPersistenceInvariantException::class, $exception);
            return;
        }
        self::fail(sprintf('Case %s was accepted.', $name));
    }

    /** @param array<string, mixed> $object */
    private function encodeObject(array $object): string
    {
        return json_encode(
            $object,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }

    /** @return array<string, mixed> */
    private function objectValue(mixed $value, string $label): array
    {
        if (! is_array($value) || array_is_list($value)) {
            self::fail(sprintf('%s must be a JSON object.', $label));
        }
        $object = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                self::fail(sprintf('%s must have string keys.', $label));
            }
            $object[$key] = $item;
        }
        return $object;
    }

    /** @return list<mixed> */
    private function listValue(mixed $value, string $label): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            self::fail(sprintf('%s must be a JSON list.', $label));
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function withResultField(array $snapshot, string $field, mixed $value): array
    {
        $result = $this->objectValue($snapshot['result'] ?? null, 'result');
        $result[$field] = $value;
        $snapshot['result'] = $result;
        return $snapshot;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function withObjectField(array $result, string $field, mixed $value): array
    {
        $result[$field] = $value;
        return $result;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function withNestedResultField(array $snapshot, string $field, array $value): array
    {
        return $this->withResultField($snapshot, $field, $value);
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function withNestedObjectField(array $result, string $objectField, string $field, mixed $value): array
    {
        $nested = $this->objectValue($result[$objectField] ?? null, $objectField);
        $nested[$field] = $value;
        $result[$objectField] = $nested;
        return $result;
    }
}
