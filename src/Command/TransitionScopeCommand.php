<?php

declare(strict_types=1);

namespace Maatify\Slug\Command;

use Maatify\Slug\DTO\AuditContextDTO;
use Maatify\Slug\DTO\BindingIdentityDTO;
use Maatify\Slug\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\DTO\ScopeTransitionClaimIntentDTO;
use Maatify\Slug\Enum\ScopeTransitionModeEnum;

final readonly class TransitionScopeCommand
{
    public function __construct(
        public BindingIdentityDTO $source,
        public ScopeProfileRequestDTO $targetScope,
        public ScopeTransitionModeEnum $mode,
        public ScopeTransitionClaimIntentDTO $targetClaimIntent,
        public int $sourceExpectedRevision,
        public AuditContextDTO $audit,
    ) {
        CommandAssertions::revision($sourceExpectedRevision);
    }
}
