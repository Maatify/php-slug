<?php

declare(strict_types=1);

namespace Maatify\Slug\Resolution;

use Maatify\Slug\Availability\SlugAvailabilityChecker;
use Maatify\Slug\Contract\SlugQueryServiceInterface;
use Maatify\Slug\Criteria\AvailabilityCriteria;
use Maatify\Slug\Criteria\CurrentSlugCriteria;
use Maatify\Slug\Criteria\ResolutionCriteria;
use Maatify\Slug\DTO\CurrentSlugDTO;
use Maatify\Slug\DTO\SlugAvailabilityDTO;
use Maatify\Slug\DTO\SlugResolutionDTO;
use Maatify\Slug\Enum\BindingStatusEnum;
use Maatify\Slug\Enum\InputFormCanonicalityEnum;
use Maatify\Slug\Enum\MatchKindEnum;
use Maatify\Slug\Enum\RegistryRoleEnum;
use Maatify\Slug\Infrastructure\Persistence\PDO\Connection\PdoCapabilityGuard;
use Maatify\Slug\Infrastructure\Persistence\PDO\Registry\PdoRegistryRepository;
use Maatify\Slug\Profile\Contracts\SlugProfileRegistryInterface;

/** Public read-only resolution boundary for WU-06. */
final readonly class SlugQueryService implements SlugQueryServiceInterface
{
    public function __construct(
        private SlugProfileRegistryInterface $profiles,
        private PdoRegistryRepository $registry,
        private SlugAvailabilityChecker $availability,
        private PdoCapabilityGuard $capabilities,
    ) {}

    public function checkAvailability(AvailabilityCriteria $criteria): SlugAvailabilityDTO
    {
        return $this->availability->check($criteria);
    }

    public function getCurrent(CurrentSlugCriteria $criteria): ?CurrentSlugDTO
    {
        $this->capabilities->assertInstalledSchemaSupported();
        $binding = $this->registry->binding($criteria->binding);
        if ($binding === null || $binding->currentClaim === null) {
            return null;
        }

        return new CurrentSlugDTO($binding, $binding->currentClaim, $binding->state->revision);
    }

    public function resolve(ResolutionCriteria $criteria): SlugResolutionDTO
    {
        $profile = $this->profiles->get($criteria->scopeProfile->expectedProfileKey);
        $lookup = $profile->canonicalizeLookup($criteria->decodedSegment);
        if ($lookup->canonicality === InputFormCanonicalityEnum::INVALID || $lookup->canonicalSlug === null) {
            return new SlugResolutionDTO(
                $criteria->scopeProfile,
                $criteria->decodedSegment,
                InputFormCanonicalityEnum::INVALID,
                null,
                null,
                MatchKindEnum::NONE,
                null,
                null,
                null,
                null,
            );
        }

        $scope = $this->registry->findScope(
            $criteria->scopeProfile->scope,
            $criteria->scopeProfile->expectedProfileKey,
        );
        if ($scope === null) {
            return $this->none($criteria, InputFormCanonicalityEnum::NOT_APPLICABLE, $lookup->canonicalSlug);
        }
        $claim = $this->registry->findClaimByScopeSlug($scope->id, $lookup->canonicalSlug);
        if ($claim === null) {
            return $this->none($criteria, InputFormCanonicalityEnum::NOT_APPLICABLE, $lookup->canonicalSlug);
        }

        $owner = $this->registry->binding($claim->toDto($this->profiles)->binding);
        if ($owner === null || $owner->currentClaim === null) {
            throw new \Maatify\Slug\Exception\SlugPersistenceInvariantException('Resolution claim owner Binding cannot be read.');
        }

        $matchKind = match ($claim->role) {
            RegistryRoleEnum::CURRENT_CANONICAL => MatchKindEnum::CURRENT,
            RegistryRoleEnum::ACTIVE_ALIAS => MatchKindEnum::ALIAS,
            RegistryRoleEnum::HISTORICAL_CANONICAL => MatchKindEnum::HISTORICAL,
            RegistryRoleEnum::RETIRED_ALIAS => MatchKindEnum::RETIRED_ALIAS,
        };

        return new SlugResolutionDTO(
            $criteria->scopeProfile,
            $criteria->decodedSegment,
            $lookup->canonicality,
            $lookup->canonicalSlug,
            $claim->toDto($this->profiles)->slug,
            $matchKind,
            $owner->state->status,
            $owner->currentClaim->slug,
            $owner->identity->entity,
            $owner->state->revision,
        );
    }

    private function none(ResolutionCriteria $criteria, InputFormCanonicalityEnum $canonicality, \Maatify\Slug\Identity\Slug $canonicalSlug): SlugResolutionDTO
    {
        return new SlugResolutionDTO(
            $criteria->scopeProfile,
            $criteria->decodedSegment,
            $canonicality,
            $canonicalSlug,
            null,
            MatchKindEnum::NONE,
            null,
            null,
            null,
            null,
        );
    }
}
