<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Command;

use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Registry\DTO\BindingIdentityDTO;
use Maatify\Slug\Scope\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeTransitionClaimIntentDTO;
use Maatify\Slug\Lifecycle\Enum\ScopeTransitionModeEnum;

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
