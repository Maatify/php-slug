<?php

declare(strict_types=1);

namespace Maatify\Slug\Criteria;

use Maatify\Slug\DTO\BindingIdentityDTO;
use Maatify\Slug\DTO\ScopeProfileRequestDTO;

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
