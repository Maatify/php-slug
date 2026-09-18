<?php

declare(strict_types=1);

namespace Maatify\Slug\Persistence\PDO\Registry;

use DateTimeImmutable;
use Maatify\Slug\Registry\DTO\BindingIdentityDTO;
use Maatify\Slug\Registry\DTO\RegistryClaimDTO;
use Maatify\Slug\Scope\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Registry\Enum\RegistryRoleEnum;
use Maatify\Slug\Registry\Value\EntityReference;
use Maatify\Slug\Text\Value\Slug;
use Maatify\Slug\Profile\Value\SlugProfileKey;
use Maatify\Slug\Profile\Contract\SlugProfileRegistryInterface;
use Maatify\Slug\Scope\Value\SlugScope;

/** @internal Raw, validated Registry row used before DTO hydration. */
final readonly class RegistryClaimRecord
{
    public function __construct(
        public int $id,
        public int $scopeId,
        public int $bindingId,
        public string $namespace,
        public ?string $localeKey,
        public ?string $contextKey,
        public string $profileKey,
        public string $entityType,
        public string $entityKey,
        public string $slugValue,
        public RegistryRoleEnum $role,
        public DateTimeImmutable $claimedAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    public function toDto(SlugProfileRegistryInterface $profiles): RegistryClaimDTO
    {
        $profileKey = new SlugProfileKey($this->profileKey);
        $profile = $profiles->get($profileKey);
        $scope = new SlugScope($this->namespace, $this->localeKey, $this->contextKey);
        $identity = new BindingIdentityDTO(
            new ScopeProfileRequestDTO($scope, $profileKey),
            new EntityReference($this->entityType, $this->entityKey),
        );

        return new RegistryClaimDTO(
            $this->id,
            $identity,
            Slug::fromProfile($profile, $this->slugValue),
            $this->role,
            $this->claimedAt,
            $this->updatedAt,
        );
    }
}
