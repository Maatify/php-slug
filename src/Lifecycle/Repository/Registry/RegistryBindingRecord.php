<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Repository\Registry;

use Maatify\Slug\Lifecycle\Enum\BindingStatusEnum;

/** @internal Raw binding row used by lifecycle coordination before DTO hydration. */
final readonly class RegistryBindingRecord
{
    /** Preserves transaction-local creation state needed by lifecycle decisions. */
    public function __construct(
        public int $id,
        public int $scopeId,
        public BindingStatusEnum $status,
        public ?int $currentRegistryId,
        public int $revision,
        public bool $createdInCurrentTransaction,
    ) {}
}
