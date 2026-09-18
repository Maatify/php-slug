<?php

declare(strict_types=1);

namespace Maatify\Slug\Query\Criteria;

use Maatify\Slug\Registry\DTO\BindingIdentityDTO;
use Maatify\Slug\Scope\DTO\ScopeProfileRequestDTO;

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
