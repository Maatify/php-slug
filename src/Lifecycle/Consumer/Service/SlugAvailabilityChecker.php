<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Consumer\Service;

use Maatify\Slug\Lifecycle\Contract\ReservedSlugPolicyInterface;
use Maatify\Slug\Lifecycle\Consumer\Criteria\AvailabilityCriteria;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\Consumer\DTO\SlugAvailabilityDTO;
use Maatify\Slug\Lifecycle\Consumer\Enum\AvailabilityStatusEnum;
use Maatify\Slug\Lifecycle\Exception\SlugPersistenceInvariantException;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Lifecycle\Repository\Registry\RegistryClaimRecord;
use Maatify\Slug\Lifecycle\Repository\Registry\RegistryRepositoryInterface;
use Maatify\Slug\Canonicalization\Service\SlugProfileRegistryInterface;
use Maatify\Slug\Lifecycle\Service\Reserved\ReservationEvaluator;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;

/**
 * Internal advisory availability read. This class never bootstraps package
 * state and never reserves a candidate.
 */
final readonly class SlugAvailabilityChecker
{
    private ReservationEvaluator $reservations;

    public function __construct(
        private RegistryRepositoryInterface $registry,
        private SlugProfileRegistryInterface $profiles,
        ReservedSlugPolicyInterface $reservedPolicy,
    ) {
        $this->reservations = new ReservationEvaluator($reservedPolicy);
    }

    public function check(AvailabilityCriteria $criteria): SlugAvailabilityDTO
    {
        $profile = $this->profiles->get($criteria->scopeProfile->expectedProfileKey);
        $lookup = $profile->canonicalizeLookup($criteria->candidate);
        if ($lookup->canonicalSlug === null) {
            return new SlugAvailabilityDTO(
                $criteria->scopeProfile,
                $criteria->candidate,
                null,
                AvailabilityStatusEnum::INVALID,
                null,
                true,
            );
        }
        $slug = $lookup->canonicalSlug;

        $this->registry->assertInstalledSchemaSupported();
        $scope = $this->registry->findScope(
            $criteria->scopeProfile->scope,
            $criteria->scopeProfile->expectedProfileKey,
        );
        if ($scope === null) {
            $status = $this->reservations->isReserved($criteria->scopeProfile->scope, $slug)
                ? AvailabilityStatusEnum::RESERVED
                : AvailabilityStatusEnum::AVAILABLE;

            return new SlugAvailabilityDTO($criteria->scopeProfile, $criteria->candidate, $slug, $status, null, true);
        }

        $claim = $this->registry->findClaimByScopeSlug($scope->id, $slug);
        if ($claim !== null) {
            $owner = $this->owner($claim);
            $status = $this->sameBinding($criteria->requestingBinding, $owner->identity)
                ? AvailabilityStatusEnum::OWNED_BY_SAME_BINDING
                : AvailabilityStatusEnum::OWNED_BY_OTHER_BINDING;

            return new SlugAvailabilityDTO($criteria->scopeProfile, $criteria->candidate, $slug, $status, $owner, true);
        }
        if ($this->reservations->isReserved($scope->scope, $slug)) {
            return new SlugAvailabilityDTO(
                $criteria->scopeProfile,
                $criteria->candidate,
                $slug,
                AvailabilityStatusEnum::RESERVED,
                null,
                true,
            );
        }

        return new SlugAvailabilityDTO(
            $criteria->scopeProfile,
            $criteria->candidate,
            $slug,
            AvailabilityStatusEnum::AVAILABLE,
            null,
            true,
        );
    }

    private function owner(RegistryClaimRecord $claim): \Maatify\Slug\Lifecycle\DTO\BindingDTO
    {
        $profileKey = new SlugProfileKey($claim->profileKey);
        $identity = new BindingIdentityDTO(
            new ScopeProfileRequestDTO(
                new SlugScope($claim->namespace, $claim->localeKey, $claim->contextKey),
                $profileKey,
            ),
            new \Maatify\Slug\Lifecycle\ValueObject\EntityReference($claim->entityType, $claim->entityKey),
        );
        $owner = $this->registry->binding($identity);
        if ($owner === null) {
            throw new SlugPersistenceInvariantException('Registry claim owner Binding cannot be read.');
        }

        return $owner;
    }

    private function sameBinding(?BindingIdentityDTO $requested, BindingIdentityDTO $owner): bool
    {
        if ($requested === null) {
            return false;
        }

        return $requested->entity->entityType === $owner->entity->entityType
            && $requested->entity->entityKey === $owner->entity->entityKey
            && $this->sameScope($requested->scopeProfile, $owner->scopeProfile);
    }

    private function sameScope(ScopeProfileRequestDTO $left, ScopeProfileRequestDTO $right): bool
    {
        return $left->expectedProfileKey->value === $right->expectedProfileKey->value
            && $left->scope->namespace === $right->scope->namespace
            && $left->scope->localeKey === $right->scope->localeKey
            && $left->scope->contextKey === $right->scope->contextKey;
    }
}
