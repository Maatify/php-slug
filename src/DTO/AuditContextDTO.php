<?php

declare(strict_types=1);

namespace Maatify\Slug\DTO;

use JsonSerializable;
use Maatify\Slug\Identity\IdentityValidator;

final readonly class AuditContextDTO implements JsonSerializable
{
    public function __construct(
        public ?string $actorKey = null,
        public ?string $reason = null,
        public ?string $correlationKey = null,
        public ?string $idempotencyKey = null,
    ) {
        if ($actorKey !== null) {
            IdentityValidator::assertAuditString($actorKey, 191, 'actorKey');
        }
        if ($reason !== null) {
            IdentityValidator::assertReason($reason);
        }
        if ($correlationKey !== null) {
            IdentityValidator::assertAuditString($correlationKey, 191, 'correlationKey');
        }
        if ($idempotencyKey !== null) {
            IdentityValidator::assertAuditString($idempotencyKey, 191, 'idempotencyKey');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'actor_key' => $this->actorKey,
            'reason' => $this->reason,
            'correlation_key' => $this->correlationKey,
            'idempotency_key' => $this->idempotencyKey,
        ];
    }
}
