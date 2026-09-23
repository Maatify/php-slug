<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\DTO;

use DateTimeImmutable;
use JsonSerializable;
use Maatify\Slug\Lifecycle\Enum\BindingStatusEnum;
use Maatify\Slug\Exception\SlugInvalidArgumentException;

/** Immutable binding snapshot with identity, lifecycle state, and current claim. */
final readonly class BindingDTO implements JsonSerializable
{
    /** Enforces consistency between binding status, current claim, and current slug. */
    public function __construct(
        public int $id,
        public BindingIdentityDTO $identity,
        public BindingStateDTO $state,
        public ?RegistryClaimDTO $currentClaim,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
        if ($id < 0) {
            throw new SlugInvalidArgumentException('id must be non-negative.');
        }
        if ($state->status === BindingStatusEnum::RELEASED && $currentClaim !== null) {
            throw new SlugInvalidArgumentException('Released binding cannot have a current claim.');
        }
        if ($state->status !== BindingStatusEnum::RELEASED && $currentClaim === null) {
            throw new SlugInvalidArgumentException('Active or inactive binding requires a current claim.');
        }
        if ($currentClaim !== null && $currentClaim->slug->value !== $state->currentSlug?->value) {
            throw new SlugInvalidArgumentException('Binding current claim must match current slug.');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'identity' => $this->identity,
            'state' => $this->state,
            'current_claim' => $this->currentClaim,
            'created_at' => $this->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z'),
            'updated_at' => $this->updatedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z'),
        ];
    }
}
