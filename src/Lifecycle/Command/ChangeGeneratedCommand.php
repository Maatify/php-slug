<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Command;

use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;

/** Requests changing a binding using generated candidates at an expected revision. */
final readonly class ChangeGeneratedCommand
{
    /** Validates non-empty source text and the optimistic-lock revision. */
    public function __construct(
        public BindingIdentityDTO $binding,
        public string $sourceText,
        public int $expectedRevision,
        public AuditContextDTO $audit,
    ) {
        CommandAssertions::nonEmpty($sourceText, 'sourceText');
        CommandAssertions::revision($expectedRevision);
    }
}
