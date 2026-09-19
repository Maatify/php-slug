<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Command;

use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\TransferReplacementIntentDTO;

final readonly class AtomicTransferCommand
{
    public function __construct(
        public BindingIdentityDTO $source,
        public BindingIdentityDTO $target,
        public string $slugCandidate,
        public ?TransferReplacementIntentDTO $sourceReplacementIntent,
        public int $sourceExpectedRevision,
        public int $targetExpectedRevision,
        public AuditContextDTO $audit,
    ) {
        CommandAssertions::nonEmpty($slugCandidate, 'slugCandidate');
        CommandAssertions::revision($sourceExpectedRevision);
        CommandAssertions::revision($targetExpectedRevision);
        if (
            $source->scopeProfile->scope->namespace !== $target->scopeProfile->scope->namespace
            || $source->scopeProfile->scope->localeKey !== $target->scopeProfile->scope->localeKey
            || $source->scopeProfile->scope->contextKey !== $target->scopeProfile->scope->contextKey
        ) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('Atomic transfer source and target must share the same scope.');
        }
        if ($source->entity->entityType === $target->entity->entityType && $source->entity->entityKey === $target->entity->entityKey) {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('Atomic transfer source and target must differ.');
        }
    }
}
