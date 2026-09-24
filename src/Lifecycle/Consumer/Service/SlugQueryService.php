<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Consumer\Service;

use Maatify\Slug\Lifecycle\Consumer\Service\SlugQueryServiceInterface;
use Maatify\Slug\Lifecycle\Consumer\Criteria\AvailabilityCriteria;
use Maatify\Slug\Lifecycle\Criteria\CurrentSlugCriteria;
use Maatify\Slug\Lifecycle\Consumer\Criteria\ResolutionCriteria;
use Maatify\Slug\Lifecycle\DTO\CurrentSlugDTO;
use Maatify\Slug\Lifecycle\Consumer\DTO\SlugAvailabilityDTO;
use Maatify\Slug\Lifecycle\Consumer\DTO\SlugResolutionDTO;
use Maatify\Slug\Lifecycle\Consumer\Service\Resolution\SlugQueryService as ResolutionService;

/** Public query adapter; resolution rules remain isolated in the Resolution layer. */
final readonly class SlugQueryService implements SlugQueryServiceInterface
{
    /** Keeps the public query surface separate from resolution implementation details. */
    public function __construct(private ResolutionService $resolution) {}

    /** Delegates advisory availability reads. */
    public function checkAvailability(AvailabilityCriteria $criteria): SlugAvailabilityDTO
    {
        return $this->resolution->checkAvailability($criteria);
    }

    /** Delegates current-claim reads. */
    public function getCurrent(CurrentSlugCriteria $criteria): ?CurrentSlugDTO
    {
        return $this->resolution->getCurrent($criteria);
    }

    /** Delegates non-mutating lookup resolution. */
    public function resolve(ResolutionCriteria $criteria): SlugResolutionDTO
    {
        return $this->resolution->resolve($criteria);
    }
}
