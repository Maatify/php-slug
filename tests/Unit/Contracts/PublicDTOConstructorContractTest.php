<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Contracts;

use Maatify\Slug\Lifecycle\DTO\AdoptionResultDTO;
use Maatify\Slug\Lifecycle\DTO\AliasDTO;
use Maatify\Slug\Lifecycle\DTO\AtomicTransferResultDTO;
use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\BindingStateDTO;
use Maatify\Slug\Lifecycle\DTO\BindingStateResultDTO;
use Maatify\Slug\Canonicalization\DTO\CanonicalSlugDTO;
use Maatify\Slug\Lifecycle\DTO\CurrentSlugDTO;
use Maatify\Slug\Canonicalization\DTO\GeneratedSlugDTO;
use Maatify\Slug\Lifecycle\DTO\HistoryEventDTO;
use Maatify\Slug\Canonicalization\DTO\LookupCanonicalizationDTO;
use Maatify\Slug\Lifecycle\DTO\RegistryClaimDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeTransitionClaimIntentDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeTransitionResultDTO;
use Maatify\Slug\Lifecycle\Consumer\DTO\SlugAvailabilityDTO;
use Maatify\Slug\Lifecycle\DTO\SlugMutationResultDTO;
use Maatify\Slug\Lifecycle\Consumer\DTO\SlugResolutionDTO;
use Maatify\Slug\Lifecycle\DTO\TransferReplacementIntentDTO;
use Maatify\Slug\Lifecycle\Consumer\Enum\AvailabilityStatusEnum;
use Maatify\Slug\Lifecycle\Enum\BindingStatusEnum;
use Maatify\Slug\Lifecycle\Enum\ChangeTypeEnum;
use Maatify\Slug\Lifecycle\Enum\ClaimIntentModeEnum;
use Maatify\Slug\Lifecycle\Enum\HistoryEventTypeEnum;
use Maatify\Slug\Lifecycle\Consumer\Enum\InputFormCanonicalityEnum;
use Maatify\Slug\Lifecycle\Consumer\Enum\MatchKindEnum;
use Maatify\Slug\Lifecycle\Enum\OperationTypeEnum;
use Maatify\Slug\Lifecycle\Enum\RegistryRoleEnum;
use Maatify\Slug\Lifecycle\Enum\ScopeTransitionModeEnum;
use Maatify\Slug\Lifecycle\ValueObject\EntityReference;
use Maatify\Slug\Canonicalization\ValueObject\Slug;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

final class PublicDTOConstructorContractTest extends TestCase
{
    public function testPublicDTOConstructorsMatchBlueprintContracts(): void
    {
        foreach (self::contracts() as $class => $expectedParameters) {
            $constructor = (new ReflectionClass($class))->getConstructor();
            if ($constructor === null) {
                self::fail(sprintf('%s must declare a constructor.', $class));
            }

            $parameters = $constructor->getParameters();
            self::assertCount(count($expectedParameters), $parameters, $class);

            foreach ($expectedParameters as $index => [$expectedName, $expectedType, $expectedNullable]) {
                $parameter = $parameters[$index] ?? null;
                self::assertNotNull($parameter, sprintf('%s is missing parameter %d.', $class, $index));
                self::assertSame($expectedName, $parameter->getName(), sprintf('%s parameter %d name.', $class, $index));

                $type = $parameter->getType();
                self::assertInstanceOf(ReflectionNamedType::class, $type, sprintf('%s parameter %d type.', $class, $index));
                self::assertSame($expectedType, $type->getName(), sprintf('%s parameter %d type.', $class, $index));
                self::assertSame($expectedNullable, $type->allowsNull(), sprintf('%s parameter %d nullability.', $class, $index));
            }
        }
    }

    /**
     * @return array<class-string, list<array{0: string, 1: string, 2: bool}>>
     */
    private static function contracts(): array
    {
        return [
            AuditContextDTO::class => [
                ['actorKey', 'string', true], ['reason', 'string', true], ['correlationKey', 'string', true], ['idempotencyKey', 'string', true],
            ],
            ScopeProfileRequestDTO::class => [
                ['scope', SlugScope::class, false], ['expectedProfileKey', SlugProfileKey::class, false],
            ],
            BindingIdentityDTO::class => [
                ['scopeProfile', ScopeProfileRequestDTO::class, false], ['entity', EntityReference::class, false],
            ],
            ScopeTransitionClaimIntentDTO::class => [
                ['mode', ClaimIntentModeEnum::class, false], ['value', 'string', false],
            ],
            TransferReplacementIntentDTO::class => [
                ['mode', ClaimIntentModeEnum::class, false], ['value', 'string', false],
            ],
            GeneratedSlugDTO::class => [
                ['profileKey', SlugProfileKey::class, false], ['source', 'string', false], ['slug', Slug::class, false],
            ],
            CanonicalSlugDTO::class => [
                ['profileKey', SlugProfileKey::class, false], ['input', 'string', false], ['slug', Slug::class, false],
            ],
            LookupCanonicalizationDTO::class => [
                ['profileKey', SlugProfileKey::class, false], ['decodedSegment', 'string', false], ['canonicality', InputFormCanonicalityEnum::class, false], ['canonicalSlug', Slug::class, true],
            ],
            ScopeDTO::class => [
                ['id', 'int', false], ['scope', SlugScope::class, false], ['profileKey', SlugProfileKey::class, false], ['createdAt', \DateTimeImmutable::class, false], ['updatedAt', \DateTimeImmutable::class, false],
            ],
            BindingStateDTO::class => [
                ['status', BindingStatusEnum::class, false], ['currentSlug', Slug::class, true], ['revision', 'int', false], ['historySequence', 'int', false],
            ],
            BindingDTO::class => [
                ['id', 'int', false], ['identity', BindingIdentityDTO::class, false], ['state', BindingStateDTO::class, false], ['currentClaim', RegistryClaimDTO::class, true], ['createdAt', \DateTimeImmutable::class, false], ['updatedAt', \DateTimeImmutable::class, false],
            ],
            RegistryClaimDTO::class => [
                ['id', 'int', false], ['binding', BindingIdentityDTO::class, false], ['slug', Slug::class, false], ['role', RegistryRoleEnum::class, false], ['claimedAt', \DateTimeImmutable::class, false], ['updatedAt', \DateTimeImmutable::class, false],
            ],
            CurrentSlugDTO::class => [
                ['binding', BindingDTO::class, false], ['claim', RegistryClaimDTO::class, false], ['revision', 'int', false],
            ],
            AliasDTO::class => [
                ['claim', RegistryClaimDTO::class, false], ['resolvableAsAlias', 'bool', false], ['bindingRevision', 'int', false],
            ],
            HistoryEventDTO::class => [
                ['id', 'int', false], ['bindingId', 'int', false], ['sequenceNo', 'int', false], ['eventType', HistoryEventTypeEnum::class, false], ['scopeSnapshot', SlugScope::class, false], ['entitySnapshot', EntityReference::class, false], ['slugSnapshot', Slug::class, true], ['previousSlugSnapshot', Slug::class, true], ['claimRoleSnapshot', RegistryRoleEnum::class, true], ['previousClaimRoleSnapshot', RegistryRoleEnum::class, true], ['relatedScope', SlugScope::class, true], ['relatedEntity', EntityReference::class, true], ['operationKey', 'string', true], ['actorKey', 'string', true], ['reason', 'string', true], ['correlationKey', 'string', true], ['occurredAt', \DateTimeImmutable::class, false], ['originalOccurredAt', \DateTimeImmutable::class, true],
            ],
            SlugAvailabilityDTO::class => [
                ['scopeProfile', ScopeProfileRequestDTO::class, false], ['requestedInput', 'string', false], ['canonicalSlug', Slug::class, true], ['status', AvailabilityStatusEnum::class, false], ['owner', BindingDTO::class, true], ['advisory', 'bool', false],
            ],
            SlugResolutionDTO::class => [
                ['scopeProfile', ScopeProfileRequestDTO::class, false], ['requestedSegment', 'string', false], ['inputCanonicality', InputFormCanonicalityEnum::class, false], ['lookupCanonicalSlug', Slug::class, true], ['matchedSlug', Slug::class, true], ['matchKind', MatchKindEnum::class, false], ['bindingStatus', BindingStatusEnum::class, true], ['currentSlug', Slug::class, true], ['entity', EntityReference::class, true], ['bindingRevision', 'int', true],
            ],
            SlugMutationResultDTO::class => [
                ['operationType', OperationTypeEnum::class, false], ['operationKey', 'string', true], ['replayed', 'bool', false], ['before', BindingDTO::class, true], ['after', BindingDTO::class, false], ['affectedClaims', 'array', false], ['previousSlug', Slug::class, true], ['currentSlug', Slug::class, true], ['changeType', ChangeTypeEnum::class, false], ['revision', 'int', false], ['historyEvents', 'array', false],
            ],
            BindingStateResultDTO::class => [
                ['before', BindingDTO::class, true], ['after', BindingDTO::class, false], ['mutated', 'bool', false], ['revision', 'int', false], ['historyEvents', 'array', false],
            ],
            ScopeTransitionResultDTO::class => [
                ['operationType', OperationTypeEnum::class, false], ['operationKey', 'string', true], ['replayed', 'bool', false], ['mode', ScopeTransitionModeEnum::class, false], ['targetCreated', 'bool', false], ['sourceResult', BindingStateResultDTO::class, false], ['targetResult', BindingStateResultDTO::class, false], ['sourceBefore', BindingDTO::class, true], ['sourceAfter', BindingDTO::class, false], ['targetBefore', BindingDTO::class, true], ['targetAfter', BindingDTO::class, false], ['sourceClaim', Slug::class, true], ['targetClaim', Slug::class, true], ['sourceRevision', 'int', false], ['targetRevision', 'int', false], ['historyEvents', 'array', false],
            ],
            AtomicTransferResultDTO::class => [
                ['operationType', OperationTypeEnum::class, false], ['operationKey', 'string', true], ['replayed', 'bool', false], ['sourceResult', BindingStateResultDTO::class, false], ['targetResult', BindingStateResultDTO::class, false], ['transferredClaim', RegistryClaimDTO::class, false], ['sourceReplacementResult', SlugMutationResultDTO::class, true], ['sourceRevision', 'int', false], ['targetRevision', 'int', false], ['historyEvents', 'array', false],
            ],
            AdoptionResultDTO::class => [
                ['operationType', OperationTypeEnum::class, false], ['operationKey', 'string', true], ['replayed', 'bool', false], ['before', BindingDTO::class, true], ['after', BindingDTO::class, false], ['adoptedClaim', RegistryClaimDTO::class, false], ['historyEvent', HistoryEventDTO::class, false],
            ],
        ];
    }
}
