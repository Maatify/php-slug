<?php

declare(strict_types=1);

namespace Maatify\Slug\Infrastructure\Persistence\PDO\Registry;

use Maatify\Slug\Enum\BindingStatusEnum;

/** @internal */
final readonly class RegistryBindingRecord
{
    public function __construct(
        public int $id,
        public int $scopeId,
        public BindingStatusEnum $status,
        public ?int $currentRegistryId,
        public int $revision,
        public bool $createdInCurrentTransaction,
    ) {}
}
