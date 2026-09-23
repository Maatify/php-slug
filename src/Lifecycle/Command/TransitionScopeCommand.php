<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Command;

use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeTransitionClaimIntentDTO;
use Maatify\Slug\Lifecycle\Enum\ScopeTransitionModeEnum;

/** Requests a MOVE or PARALLEL transition from one scope to another. */
final readonly class TransitionScopeCommand
{
    /** Validates the source revision; mode and claim intent remain explicit command inputs. */
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
