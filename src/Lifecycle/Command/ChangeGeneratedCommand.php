<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Command;

use Maatify\Slug\Lifecycle\DTO\AuditContextDTO;
use Maatify\Slug\Registry\DTO\BindingIdentityDTO;

final readonly class ChangeGeneratedCommand
{
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
