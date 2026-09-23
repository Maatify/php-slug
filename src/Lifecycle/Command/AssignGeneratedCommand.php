<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Command;

use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;

/** Requests generated assignment from source text, optionally guarded by a revision. */
final readonly class AssignGeneratedCommand
{
    /** Validates non-empty source text and optional optimistic-lock revision. */
    public function __construct(
        public BindingIdentityDTO $binding,
        public string $sourceText,
        public ?int $expectedRevision,
        public AuditContextDTO $audit,
    ) {
        CommandAssertions::nonEmpty($sourceText, 'sourceText');
        CommandAssertions::revision($expectedRevision);
    }
}
