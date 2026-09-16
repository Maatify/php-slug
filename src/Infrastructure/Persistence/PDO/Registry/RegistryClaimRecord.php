<?php

declare(strict_types=1);

namespace Maatify\Slug\Infrastructure\Persistence\PDO\Registry;

use DateTimeImmutable;
use Maatify\Slug\DTO\BindingIdentityDTO;
use Maatify\Slug\DTO\RegistryClaimDTO;
use Maatify\Slug\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Enum\RegistryRoleEnum;
use Maatify\Slug\Identity\EntityReference;
use Maatify\Slug\Identity\Slug;
use Maatify\Slug\Identity\SlugProfileKey;
use Maatify\Slug\Profile\Contracts\SlugProfileRegistryInterface;
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
