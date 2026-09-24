<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Contracts;

use Maatify\Slug\Lifecycle\DTO\AliasDTO;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Canonicalization\DTO\CanonicalSlugDTO;
use Maatify\Slug\Lifecycle\DTO\CurrentSlugDTO;
use Maatify\Slug\Canonicalization\DTO\GeneratedSlugDTO;
use Maatify\Slug\Canonicalization\DTO\LookupCanonicalizationDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeOperationalSummaryDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeTransitionClaimIntentDTO;
use Maatify\Slug\Lifecycle\Consumer\DTO\SlugAvailabilityDTO;
use Maatify\Slug\Lifecycle\Consumer\DTO\SlugResolutionDTO;
use Maatify\Slug\Lifecycle\DTO\TransferReplacementIntentDTO;
use Maatify\Slug\Lifecycle\Consumer\Enum\AvailabilityStatusEnum;
use Maatify\Slug\Lifecycle\Enum\ClaimIntentModeEnum;
use Maatify\Slug\Lifecycle\Consumer\Enum\InputFormCanonicalityEnum;
use Maatify\Slug\Lifecycle\Consumer\Enum\MatchKindEnum;
use PHPUnit\Framework\TestCase;

final class DTOJsonSerializationTest extends TestCase
{
    /** @return iterable<string, array{0: \JsonSerializable, 1: list<string>}> */
    public static function dtoCases(): iterable
    {
        $identity = ContractFixtures::identity(1);
        $binding = ContractFixtures::binding($identity, 100);
        $claim = $binding->currentClaim;
        if ($claim === null) {
            throw new \LogicException('Fixture binding must have a claim.');
        }
        $slug = ContractFixtures::slug('hello');
        $scope = $identity->scopeProfile;

        yield 'audit' => [new AuditContextDTO('actor', ' reason ', 'correlation', '0123456789abcdef0123456789abcdef'), ['actor_key', 'reason', 'correlation_key', 'idempotency_key']];
        yield 'generated' => [new GeneratedSlugDTO($scope->expectedProfileKey, 'Hello', $slug), ['profile_key', 'source', 'slug']];
        yield 'canonical' => [new CanonicalSlugDTO($scope->expectedProfileKey, 'hello', $slug), ['profile_key', 'input', 'slug']];
        yield 'lookup' => [new LookupCanonicalizationDTO($scope->expectedProfileKey, 'hello', InputFormCanonicalityEnum::CANONICAL, $slug), ['profile_key', 'decoded_segment', 'canonicality', 'canonical_slug']];
        yield 'scope' => [new ScopeDTO(1, $scope->scope, $scope->expectedProfileKey, ContractFixtures::date(), ContractFixtures::date()), ['id', 'scope', 'profile_key', 'created_at', 'updated_at']];
        yield 'scope operational summary' => [new ScopeOperationalSummaryDTO(new ScopeDTO(1, $scope->scope, $scope->expectedProfileKey, ContractFixtures::date(), ContractFixtures::date()), 3, 1, 1, 1, 4, 1, 1, 1, 1, 2), ['scope', 'bindings_total', 'bindings_active', 'bindings_inactive', 'bindings_released', 'registry_claims_total', 'registry_current_canonical', 'registry_historical_canonical', 'registry_active_aliases', 'registry_retired_aliases', 'history_events_total']];
        yield 'current' => [new CurrentSlugDTO($binding, $claim, 1), ['binding', 'claim', 'revision']];
        yield 'alias' => [new AliasDTO($claim, true, 1), ['claim', 'resolvable_as_alias', 'binding_revision']];
        yield 'availability' => [new SlugAvailabilityDTO($scope, 'hello', $slug, AvailabilityStatusEnum::AVAILABLE, $binding, true), ['scope_profile', 'requested_input', 'canonical_slug', 'status', 'owner', 'advisory']];
        yield 'resolution' => [new SlugResolutionDTO($scope, 'hello', InputFormCanonicalityEnum::CANONICAL, $slug, $slug, MatchKindEnum::CURRENT, \Maatify\Slug\Lifecycle\Enum\BindingStatusEnum::ACTIVE, $slug, $identity->entity, 1), ['scope_profile', 'requested_segment', 'input_canonicality', 'lookup_canonical_slug', 'matched_slug', 'match_kind', 'binding_status', 'current_slug', 'entity', 'binding_revision']];
        yield 'transition intent' => [new ScopeTransitionClaimIntentDTO(ClaimIntentModeEnum::EXACT, 'hello'), ['mode', 'value']];
        yield 'transfer intent' => [new TransferReplacementIntentDTO(ClaimIntentModeEnum::GENERATED, 'Hello source'), ['mode', 'value']];
        yield 'mutation result' => [ContractFixtures::mutation(), ['operation_type', 'operation_key', 'replayed', 'before', 'after', 'affected_claims', 'previous_slug', 'current_slug', 'change_type', 'revision', 'history_events']];
        yield 'transition result' => [ContractFixtures::transition(), ['operation_type', 'operation_key', 'replayed', 'mode', 'target_created', 'source_result', 'target_result', 'source_before', 'source_after', 'target_before', 'target_after', 'source_claim', 'target_claim', 'source_revision', 'target_revision', 'history_events']];
        yield 'transfer result' => [ContractFixtures::transfer(), ['operation_type', 'operation_key', 'replayed', 'source_result', 'target_result', 'transferred_claim', 'source_replacement_result', 'source_revision', 'target_revision', 'history_events']];
        yield 'adoption result' => [ContractFixtures::adoption(), ['operation_type', 'operation_key', 'replayed', 'before', 'after', 'adopted_claim', 'history_event']];
    }

    /** @param list<string> $keys */
    #[\PHPUnit\Framework\Attributes\DataProvider('dtoCases')]
    public function testEachPublicDTOSerializesItsExactTopLevelShape(\JsonSerializable $dto, array $keys): void
    {
        $serialized = $this->serializedObject($dto);

        self::assertSame($keys, array_keys($serialized));
        json_encode($dto, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function serializedObject(\JsonSerializable $dto): array
    {
        $serialized = $dto->jsonSerialize();
        if (! is_array($serialized) || array_is_list($serialized)) {
            self::fail('DTO must serialize as an object.');
        }
        $object = [];
        foreach ($serialized as $key => $value) {
            if (! is_string($key)) {
                self::fail('DTO object keys must be strings.');
            }
            $object[$key] = $value;
        }
        return $object;
    }
}
