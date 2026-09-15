<?php

declare(strict_types=1);

namespace Maatify\Slug\Command;

use Maatify\Slug\DTO\AuditContextDTO;
use Maatify\Slug\DTO\BindingIdentityDTO;

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
