<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\DTO;

use DateTimeImmutable;
use JsonSerializable;
use Maatify\Slug\Canonicalization\ValueObject\SlugProfileKey;
use Maatify\Slug\Lifecycle\ValueObject\SlugScope;

/** Immutable persisted scope/profile association used by package-owned storage. */
final readonly class ScopeDTO implements JsonSerializable
{
    /** Enforces a non-negative persisted scope identifier. */
    public function __construct(
        public int $id,
        public SlugScope $scope,
        public SlugProfileKey $profileKey,
        public DateTimeImmutable $createdAt,
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
            'scope' => $this->scope,
            'profile_key' => $this->profileKey->value,
            'created_at' => $this->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z'),
            'updated_at' => $this->updatedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z'),
        ];
    }
}
