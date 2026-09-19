<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Consumer\Criteria;

use Maatify\Slug\Lifecycle\DTO\BindingIdentityDTO;
use Maatify\Slug\Lifecycle\DTO\ScopeProfileRequestDTO;

final readonly class AvailabilityCriteria
{
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
