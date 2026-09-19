<?php

declare(strict_types=1);

namespace Maatify\Slug\Tests\Unit\Contracts;

use DateTimeImmutable;
use Maatify\Slug\Lifecycle\DTO\AdoptionResultDTO;
use Maatify\Slug\Lifecycle\DTO\BindingDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\BindingStateDTO;
use Maatify\Slug\Lifecycle\DTO\BindingStateResultDTO;
use Maatify\Slug\Canonicalization\DTO\CanonicalSlugDTO;
use Maatify\Slug\Canonicalization\DTO\GeneratedSlugDTO;
use Maatify\Slug\Lifecycle\DTO\HistoryEventDTO;
use Maatify\Slug\Canonicalization\DTO\LookupCanonicalizationDTO;
use Maatify\Slug\Lifecycle\DTO\RegistryClaimDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeTransitionResultDTO;
use Maatify\Slug\Lifecycle\DTO\SlugMutationResultDTO;
use Maatify\Slug\Lifecycle\DTO\AtomicTransferResultDTO;
use Maatify\Slug\Lifecycle\Enum\BindingStatusEnum;
use Maatify\Slug\Lifecycle\Enum\ChangeTypeEnum;
use Maatify\Slug\Lifecycle\Enum\HistoryEventTypeEnum;
use Maatify\Slug\Lifecycle\Consumer\Enum\InputFormCanonicalityEnum;
use Maatify\Slug\Lifecycle\Enum\OperationTypeEnum;
use Maatify\Slug\Lifecycle\Enum\RegistryRoleEnum;
use Maatify\Slug\Lifecycle\Enum\ScopeTransitionModeEnum;
use Maatify\Slug\Canonicalization\Exception\SlugProfileNotFoundException;
use Maatify\Slug\Lifecycle\ValueObject\EntityReference;
use Maatify\Slug\Canonicalization\ValueObject\Slug;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Canonicalization\Contract\SlugProfileInterface;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistryInterface;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;

final class ContractFixtures
{
    public static function profile(): SlugProfileInterface
    {
        return new FixtureProfile();
    }

    public static function registry(): SlugProfileRegistryInterface
    {
        return new FixtureRegistry(self::profile());
    }

    public static function date(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01T00:00:00.123456Z');
    }

    public static function scope(string $namespace = 'catalog'): SlugScope
    {
        return new SlugScope($namespace, null, null);
    }

    public static function identity(int $entityId, string $namespace = 'catalog'): BindingIdentityDTO
    {
        return new BindingIdentityDTO(
            new ScopeProfileRequestDTO(self::scope($namespace), new SlugProfileKey('ascii-v1')),
            new EntityReference('product', (string) $entityId),
        );
    }

    public static function slug(string $value): Slug
    {
        return Slug::fromProfile(self::profile(), $value);
    }

    public static function claim(
        BindingIdentityDTO $identity,
        Slug $slug,
        int $id,
        RegistryRoleEnum $role = RegistryRoleEnum::CURRENT_CANONICAL,
    ): RegistryClaimDTO {
        return new RegistryClaimDTO($id, $identity, $slug, $role, self::date(), self::date());
    }

    public static function binding(
        BindingIdentityDTO $identity,
        int $id,
        string $slugValue = 'hello',
        int $claimId = 200,
        BindingStatusEnum $status = BindingStatusEnum::ACTIVE,
        int $revision = 1,
        ?RegistryRoleEnum $role = RegistryRoleEnum::CURRENT_CANONICAL,
    ): BindingDTO {
        $slug = self::slug($slugValue);
        $claim = $status === BindingStatusEnum::RELEASED || $role === null ? null : self::claim($identity, $slug, $claimId, $role);
        return new BindingDTO(
            $id,
            $identity,
            new BindingStateDTO($status, $status === BindingStatusEnum::RELEASED ? null : $slug, $revision, $revision),
            $claim,
            self::date(),
            self::date(),
        );
    }

    public static function history(
        int $bindingId,
        int $id,
        int $sequence,
        BindingIdentityDTO $identity,
        HistoryEventTypeEnum $eventType,
        ?Slug $slug,
        ?RegistryRoleEnum $role,
        ?Slug $previousSlug = null,
        ?RegistryRoleEnum $previousRole = null,
        ?DateTimeImmutable $originalOccurredAt = null,
    ): HistoryEventDTO {
        return new HistoryEventDTO(
            $id,
            $bindingId,
            $sequence,
            $eventType,
            $identity->scopeProfile->scope,
            $identity->entity,
            $slug,
            $previousSlug,
            $role,
            $previousRole,
            null,
            null,
            null,
            null,
            null,
            null,
            self::date(),
            $originalOccurredAt,
        );
    }

    public static function mutation(): SlugMutationResultDTO
    {
        $identity = self::identity(1);
        $after = self::binding($identity, 100);
        $slug = self::slug('hello');
        $claim = $after->currentClaim;
        if ($claim === null) {
            throw new \LogicException('Fixture binding must have a current claim.');
        }
        return new SlugMutationResultDTO(
            OperationTypeEnum::ASSIGN_EXACT,
            null,
            false,
            null,
            $after,
            [$claim],
            null,
            $slug,
            ChangeTypeEnum::ASSIGNED,
            1,
            [self::history(100, 500, 1, $identity, HistoryEventTypeEnum::ASSIGNED, $slug, RegistryRoleEnum::CURRENT_CANONICAL)],
        );
    }

    public static function transition(): ScopeTransitionResultDTO
    {
        $sourceIdentity = self::identity(1, 'catalog');
        $targetIdentity = self::identity(1, 'store');
        $sourceBefore = self::binding($sourceIdentity, 100, 'hello', 200, BindingStatusEnum::ACTIVE, 1);
        $sourceAfter = self::binding($sourceIdentity, 100, 'hello', 200, BindingStatusEnum::INACTIVE, 2);
        $targetAfter = self::binding($targetIdentity, 300, 'hello', 201, BindingStatusEnum::ACTIVE, 1);
        $sourceClaim = $sourceAfter->currentClaim;
        $targetClaim = $targetAfter->currentClaim;
        if ($sourceClaim === null || $targetClaim === null) {
            throw new \LogicException('MOVE transition bindings must have current claims.');
        }
        $sourceEvent = self::history(100, 501, 2, $sourceIdentity, HistoryEventTypeEnum::SCOPE_TRANSITIONED_OUT, self::slug('hello'), RegistryRoleEnum::CURRENT_CANONICAL);
        $targetEvent = self::history(300, 502, 1, $targetIdentity, HistoryEventTypeEnum::SCOPE_TRANSITIONED_IN, self::slug('hello'), RegistryRoleEnum::CURRENT_CANONICAL);
        return new ScopeTransitionResultDTO(
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
    }

    public static function transfer(): AtomicTransferResultDTO
    {
        $sourceIdentity = self::identity(1);
        $targetIdentity = self::identity(2);
        $sourceBefore = self::binding($sourceIdentity, 100, 'hello', 200, BindingStatusEnum::ACTIVE, 1);
        $sourceAfter = self::binding($sourceIdentity, 100, 'replacement', 202, BindingStatusEnum::ACTIVE, 2);
        $targetBefore = self::binding($targetIdentity, 300, 'hello', 201, BindingStatusEnum::RELEASED, 0);
        $targetAfter = self::binding($targetIdentity, 300, 'hello', 201, BindingStatusEnum::ACTIVE, 1);
        $hello = self::slug('hello');
        $replacement = self::slug('replacement');
        $sourceReplacementEvent = self::history(100, 506, 2, $sourceIdentity, HistoryEventTypeEnum::CHANGED, $replacement, RegistryRoleEnum::CURRENT_CANONICAL, $hello, RegistryRoleEnum::CURRENT_CANONICAL);
        $sourceTransferEvent = self::history(100, 504, 3, $sourceIdentity, HistoryEventTypeEnum::OWNERSHIP_TRANSFERRED_OUT, $hello, RegistryRoleEnum::CURRENT_CANONICAL);
        $targetTransferEvent = self::history(300, 505, 1, $targetIdentity, HistoryEventTypeEnum::OWNERSHIP_TRANSFERRED_IN, $hello, RegistryRoleEnum::CURRENT_CANONICAL);
        $transferredClaim = $targetAfter->currentClaim;
        $replacementClaim = $sourceAfter->currentClaim;
        if ($transferredClaim === null || $replacementClaim === null) {
            throw new \LogicException('Fixture transfer bindings must have current claims.');
        }
        return new AtomicTransferResultDTO(
            OperationTypeEnum::ATOMIC_TRANSFER,
            null,
            false,
            new BindingStateResultDTO($sourceBefore, $sourceAfter, true, 2, [$sourceReplacementEvent, $sourceTransferEvent]),
            new BindingStateResultDTO($targetBefore, $targetAfter, true, 1, [$targetTransferEvent]),
            $transferredClaim,
            new SlugMutationResultDTO(
                OperationTypeEnum::ATOMIC_TRANSFER,
                null,
                false,
                $sourceBefore,
                $sourceAfter,
                [$replacementClaim],
                $hello,
                $replacement,
                ChangeTypeEnum::CHANGED,
                2,
                [$sourceReplacementEvent],
            ),
            2,
            1,
            [$sourceReplacementEvent, $sourceTransferEvent, $targetTransferEvent],
        );
    }

    public static function adoption(): AdoptionResultDTO
    {
        $identity = self::identity(4);
        $after = self::binding($identity, 400, 'adopted', 401);
        $slug = self::slug('adopted');
        $adoptedClaim = $after->currentClaim;
        if ($adoptedClaim === null) {
            throw new \LogicException('Fixture adoption binding must have a current claim.');
        }
        return new AdoptionResultDTO(
            OperationTypeEnum::ADOPT_CURRENT,
            null,
            false,
            null,
            $after,
            $adoptedClaim,
            self::history(400, 600, 1, $identity, HistoryEventTypeEnum::ADOPTED_CURRENT, $slug, RegistryRoleEnum::CURRENT_CANONICAL, null, null, self::date()),
        );
    }
}

final class FixtureProfile implements SlugProfileInterface
{
    private SlugProfileKey $profileKey;

    public function __construct()
    {
        $this->profileKey = new SlugProfileKey('ascii-v1');
    }

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
        if (preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $candidate) !== 1) {
            throw new \InvalidArgumentException('Not a fixture canonical slug.');
        }
    }
}

final class FixtureRegistry implements SlugProfileRegistryInterface
{
    /** @var array<string, SlugProfileInterface> */
    private array $profiles;

    public function __construct(SlugProfileInterface $profile)
    {
        $this->profiles = [$profile->key()->value => $profile];
    }

    public function register(SlugProfileInterface $profile): void
    {
        $this->profiles[$profile->key()->value] = $profile;
    }

    public function get(SlugProfileKey $key): SlugProfileInterface
    {
        if (! isset($this->profiles[$key->value])) {
            throw new SlugProfileNotFoundException('Unknown fixture profile.');
        }
        return $this->profiles[$key->value];
    }

    public function has(SlugProfileKey $key): bool
    {
        return isset($this->profiles[$key->value]);
    }
}
