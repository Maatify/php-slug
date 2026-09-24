<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Consumer\Criteria;

use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;

/** Describes a non-mutating availability check within a requested scope/profile. */
final readonly class AvailabilityCriteria
{
    /** Requires a non-empty candidate and optionally identifies the requesting binding. */
    public function __construct(
        public ScopeProfileRequestDTO $scopeProfile,
        public string $candidate,
        public ?BindingIdentityDTO $requestingBinding = null,
    ) {
        if ($candidate === '') {
            throw new \Maatify\Slug\Exception\SlugInvalidArgumentException('candidate must be non-empty.');
        }
    }
}
