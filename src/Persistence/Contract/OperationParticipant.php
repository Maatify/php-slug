<?php

declare(strict_types=1);

namespace Maatify\Slug\Persistence\Contract;

use Maatify\Slug\Exception\SlugInvalidArgumentException;
use Maatify\Slug\Identity\IdentityValidator;

final readonly class OperationParticipant
{
    public function __construct(
        public int $bindingId,
        public string $idempotencyKey,
        public string $role,
    ) {
        if ($bindingId < 0) {
            throw new SlugInvalidArgumentException('Operation participant binding id must be non-negative.');
        }
        IdentityValidator::assertOpaqueString($idempotencyKey, 191, 'idempotencyKey');
        if (! in_array($role, ['SINGLE', 'SOURCE', 'TARGET'], true)) {
            throw new SlugInvalidArgumentException('Unknown operation participant role.');
        }
    }
}
