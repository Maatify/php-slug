<?php

declare(strict_types=1);

namespace Maatify\Slug\DTO;

use DateTimeImmutable;
use JsonSerializable;
use Maatify\Slug\Enum\RegistryRoleEnum;
use Maatify\Slug\Identity\Slug;

final readonly class RegistryClaimDTO implements JsonSerializable
{
    public function __construct(
        public int $id,
        public BindingIdentityDTO $binding,
        public Slug $slug,
        public RegistryRoleEnum $role,
        public DateTimeImmutable $claimedAt,
        public DateTimeImmutable $updatedAt,
    ) {
        DTOAssertions::nonNegative($id, 'id');
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'binding' => $this->binding,
            'slug' => $this->slug->value,
            'role' => $this->role->value,
            'claimed_at' => $this->claimedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z'),
            'updated_at' => $this->updatedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z'),
        ];
    }
}
