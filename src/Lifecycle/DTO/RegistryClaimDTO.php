<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\DTO;

use DateTimeImmutable;
use JsonSerializable;
use Maatify\Slug\Lifecycle\Enum\RegistryRoleEnum;
use Maatify\Slug\Canonicalization\ValueObject\Slug;

/** Immutable read model for one canonical, historical, or alias registry claim. */
final readonly class RegistryClaimDTO implements JsonSerializable
{
    /** Stores the claim identity, canonical slug, role, and persistence timestamps. */
    public function __construct(
        public int $id,
        public BindingIdentityDTO $binding,
        public Slug $slug,
        public RegistryRoleEnum $role,
        public DateTimeImmutable $claimedAt,
        public DateTimeImmutable $updatedAt,
    ) {
        if ($id < 0) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('id must be non-negative.');
        }
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
