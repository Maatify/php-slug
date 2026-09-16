<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Contracts;

use DateTimeImmutable;
use Maatify\Slug\Contract\ResultSnapshot\ResultSnapshotDecoder;
use Maatify\Slug\Contract\ResultSnapshot\ResultSnapshotEncoder;
use Maatify\Slug\DTO\BindingDTO;
use Maatify\Slug\DTO\BindingIdentityDTO;
use Maatify\Slug\DTO\BindingStateDTO;
use Maatify\Slug\DTO\BindingStateResultDTO;
use Maatify\Slug\DTO\CanonicalSlugDTO;
use Maatify\Slug\DTO\GeneratedSlugDTO;
use Maatify\Slug\DTO\HistoryEventDTO;
use Maatify\Slug\DTO\LookupCanonicalizationDTO;
use Maatify\Slug\DTO\RegistryClaimDTO;
use Maatify\Slug\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\DTO\ScopeTransitionResultDTO;
use Maatify\Slug\Enum\BindingStatusEnum;
use Maatify\Slug\Enum\HistoryEventTypeEnum;
use Maatify\Slug\Enum\InputFormCanonicalityEnum;
use Maatify\Slug\Enum\OperationTypeEnum;
use Maatify\Slug\Enum\RegistryRoleEnum;
use Maatify\Slug\Enum\ScopeTransitionModeEnum;
use Maatify\Slug\Exception\SlugPersistenceInvariantException;
use Maatify\Slug\Exception\SlugProfileNotFoundException;
use Maatify\Slug\Identity\EntityReference;
use Maatify\Slug\Identity\Slug;
use Maatify\Slug\Identity\SlugProfileKey;
use Maatify\Slug\Profile\Contracts\SlugProfileInterface;
use Maatify\Slug\Profile\Contracts\SlugProfileRegistryInterface;
use Maatify\Slug\Scope\Value\SlugScope;
use PHPUnit\Framework\TestCase;

final class ParticipantAwareTransitionSnapshotTest extends TestCase
{
    public function testTransitionSnapshotUsesTheProfileOfEachHistoryParticipant(): void
    {
        [$snapshot, $registry, $sourceBindingId, $targetBindingId] = $this->transitionSnapshot();

        $decoded = ResultSnapshotDecoder::decode($snapshot, $registry);
        if (! $decoded instanceof ScopeTransitionResultDTO) {
            self::fail('Expected a transition result.');
        }

        self::assertSame($sourceBindingId, $decoded->sourceAfter->id);
        self::assertSame($targetBindingId, $decoded->targetAfter->id);
        self::assertSame('src-hello', $decoded->historyEvents[0]->slugSnapshot?->value);
        self::assertSame('tgt-world', $decoded->historyEvents[1]->slugSnapshot?->value);
        self::assertSame('src-hello', $decoded->sourceResult->historyEvents[0]->slugSnapshot?->value);
        self::assertSame('tgt-world', $decoded->targetResult->historyEvents[0]->slugSnapshot?->value);
    }

    public function testTransitionSnapshotRejectsUnknownOrMismatchedParticipantProfileEvidence(): void
    {
        [$snapshot, $registry, $sourceBindingId] = $this->transitionSnapshot();
        $valid = $this->decodeObject($snapshot);
        $result = $this->objectValue($valid['result'] ?? null, 'result');
        $history = $this->listValue($result['history_events'] ?? null, 'history_events');
        $targetEvent = $this->objectValue($history[1] ?? null, 'target history event');

        $wrongParticipant = $valid;
        $wrongParticipantResult = $result;
        $wrongParticipantEvent = $targetEvent;
        $wrongParticipantEvent['binding_id'] = $sourceBindingId;
        $wrongParticipantHistory = $history;
        $wrongParticipantHistory[1] = $wrongParticipantEvent;
        $wrongParticipantResult['history_events'] = $wrongParticipantHistory;
        $wrongParticipant['result'] = $wrongParticipantResult;
        $this->assertDecodeRejects($this->encodeObject($wrongParticipant), $registry, 'target event under source participant');

        $unknownParticipant = $valid;
        $unknownParticipantResult = $result;
        $unknownParticipantEvent = $targetEvent;
        $unknownParticipantEvent['binding_id'] = 999;
        $unknownParticipantHistory = $history;
        $unknownParticipantHistory[1] = $unknownParticipantEvent;
        $unknownParticipantResult['history_events'] = $unknownParticipantHistory;
        $unknownParticipant['result'] = $unknownParticipantResult;
        $this->assertDecodeRejects($this->encodeObject($unknownParticipant), $registry, 'unknown participant');

        $unknownProfile = $valid;
        $unknownProfileResult = $result;
        $targetAfter = $this->objectValue($unknownProfileResult['target_after'] ?? null, 'target after');
        $targetIdentity = $this->objectValue($targetAfter['identity'] ?? null, 'target identity');
        $targetScopeProfile = $this->objectValue($targetIdentity['scope_profile'] ?? null, 'target scope profile');
        $targetScopeProfile['expected_profile_key'] = 'missing-v1';
        $targetIdentity['scope_profile'] = $targetScopeProfile;
        $targetAfter['identity'] = $targetIdentity;
        $unknownProfileResult['target_after'] = $targetAfter;
        $unknownProfile['result'] = $unknownProfileResult;
        $this->assertDecodeRejects($this->encodeObject($unknownProfile), $registry, 'unknown target profile');
    }

    /** @return array{0: string, 1: SlugProfileRegistryInterface, 2: int, 3: int} */
    private function transitionSnapshot(): array
    {
        $sourceProfile = new DifferentialFixtureProfile(new SlugProfileKey('source-v1'), 'src');
        $targetProfile = new DifferentialFixtureProfile(new SlugProfileKey('target-v1'), 'tgt');
        $registry = new DifferentialFixtureRegistry($sourceProfile, $targetProfile);
        $sourceIdentity = new BindingIdentityDTO(
            new ScopeProfileRequestDTO(new SlugScope('source', null, null), $sourceProfile->key()),
            new EntityReference('product', '1'),
        );
        $targetIdentity = new BindingIdentityDTO(
            new ScopeProfileRequestDTO(new SlugScope('target', null, null), $targetProfile->key()),
            new EntityReference('product', '1'),
        );
        $sourceBefore = $this->binding($sourceIdentity, $sourceProfile, 100, 'src-hello', 200, BindingStatusEnum::ACTIVE, 1);
        $sourceAfter = $this->binding($sourceIdentity, $sourceProfile, 100, 'src-hello', 200, BindingStatusEnum::INACTIVE, 2);
        $targetAfter = $this->binding($targetIdentity, $targetProfile, 300, 'tgt-world', 301, BindingStatusEnum::ACTIVE, 1);
        $sourceEvent = $this->history($sourceIdentity, 100, 501, 2, HistoryEventTypeEnum::SCOPE_TRANSITIONED_OUT, Slug::fromProfile($sourceProfile, 'src-hello'));
        $targetEvent = $this->history($targetIdentity, 300, 502, 1, HistoryEventTypeEnum::SCOPE_TRANSITIONED_IN, Slug::fromProfile($targetProfile, 'tgt-world'));
        $sourceClaim = $sourceAfter->currentClaim;
        $targetClaim = $targetAfter->currentClaim;
        if ($sourceClaim === null || $targetClaim === null) {
            throw new \LogicException('Differential transition bindings must have current claims.');
        }

        $transition = new ScopeTransitionResultDTO(
            OperationTypeEnum::TRANSITION_SCOPE,
            null,
            false,
            ScopeTransitionModeEnum::MOVE,
            true,
            new BindingStateResultDTO($sourceBefore, $sourceAfter, true, 2, [$sourceEvent]),
            new BindingStateResultDTO(null, $targetAfter, true, 1, [$targetEvent]),
            $sourceBefore,
            $sourceAfter,
            null,
            $targetAfter,
            $sourceClaim->slug,
            $targetClaim->slug,
            2,
            1,
            [$sourceEvent, $targetEvent],
        );

        return [ResultSnapshotEncoder::encode($transition), $registry, 100, 300];
    }

    private function binding(
        BindingIdentityDTO $identity,
        SlugProfileInterface $profile,
        int $id,
        string $slugValue,
        int $claimId,
        BindingStatusEnum $status,
        int $revision,
    ): BindingDTO {
        $slug = Slug::fromProfile($profile, $slugValue);
        return new BindingDTO(
            $id,
            $identity,
            new BindingStateDTO($status, $slug, $revision, $revision),
            new RegistryClaimDTO($claimId, $identity, $slug, RegistryRoleEnum::CURRENT_CANONICAL, $this->date(), $this->date()),
            $this->date(),
            $this->date(),
        );
    }

    private function history(
        BindingIdentityDTO $identity,
        int $bindingId,
        int $id,
        int $sequence,
        HistoryEventTypeEnum $eventType,
        Slug $slug,
    ): HistoryEventDTO {
        return new HistoryEventDTO(
            $id,
            $bindingId,
            $sequence,
            $eventType,
            $identity->scopeProfile->scope,
            $identity->entity,
            $slug,
            null,
            RegistryRoleEnum::CURRENT_CANONICAL,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $this->date(),
            null,
        );
    }

    private function date(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01T00:00:00.123456Z');
    }

    private function assertDecodeRejects(string $json, SlugProfileRegistryInterface $registry, string $label): void
    {
        try {
            ResultSnapshotDecoder::decode($json, $registry);
        } catch (SlugPersistenceInvariantException $exception) {
            self::assertInstanceOf(SlugPersistenceInvariantException::class, $exception);
            return;
        }
        self::fail(sprintf('Malformed case %s was accepted.', $label));
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

    /** @param array<string, mixed> $object */
    private function encodeObject(array $object): string
    {
        return json_encode(
            $object,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }
}

final class DifferentialFixtureProfile implements SlugProfileInterface
{
    public function __construct(
        private SlugProfileKey $profileKey,
        private string $prefix,
    ) {}

    public function key(): SlugProfileKey
    {
        return $this->profileKey;
    }

    public function generateFromSource(string $source): GeneratedSlugDTO
    {
        return new GeneratedSlugDTO($this->profileKey, $source, Slug::fromProfile($this, $source));
    }

    public function canonicalizeClaim(string $candidate): CanonicalSlugDTO
    {
        return new CanonicalSlugDTO($this->profileKey, $candidate, Slug::fromProfile($this, $candidate));
    }

    public function canonicalizeLookup(string $decodedSegment): LookupCanonicalizationDTO
    {
        return new LookupCanonicalizationDTO($this->profileKey, $decodedSegment, InputFormCanonicalityEnum::CANONICAL, Slug::fromProfile($this, $decodedSegment));
    }

    public function assertCanonicalSlug(string $candidate): void
    {
        if (preg_match('/\\A' . preg_quote($this->prefix, '/') . '-[a-z]+\\z/', $candidate) !== 1) {
            throw new \InvalidArgumentException('Not a differential fixture canonical slug.');
        }
    }
}

final class DifferentialFixtureRegistry implements SlugProfileRegistryInterface
{
    /** @var array<string, SlugProfileInterface> */
    private array $profiles = [];

    public function __construct(SlugProfileInterface ...$profiles)
    {
        foreach ($profiles as $profile) {
            $this->register($profile);
        }
    }

    public function register(SlugProfileInterface $profile): void
    {
        $this->profiles[$profile->key()->value] = $profile;
    }

    public function get(SlugProfileKey $key): SlugProfileInterface
    {
        if (! isset($this->profiles[$key->value])) {
            throw new SlugProfileNotFoundException('Unknown differential fixture profile.');
        }
        return $this->profiles[$key->value];
    }

    public function has(SlugProfileKey $key): bool
    {
        return isset($this->profiles[$key->value]);
    }
}
