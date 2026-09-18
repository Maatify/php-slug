<?php

declare(strict_types=1);

namespace Maatify\Slug\Query;

use Maatify\Slug\Query\Contract\SlugQueryServiceInterface;
use Maatify\Slug\Query\Criteria\AvailabilityCriteria;
use Maatify\Slug\Query\Criteria\CurrentSlugCriteria;
use Maatify\Slug\Query\Criteria\ResolutionCriteria;
use Maatify\Slug\Query\DTO\CurrentSlugDTO;
use Maatify\Slug\Query\DTO\SlugAvailabilityDTO;
use Maatify\Slug\Query\DTO\SlugResolutionDTO;
use Maatify\Slug\Query\Resolution\SlugQueryService as ResolutionService;

/** Public query adapter; resolution rules remain isolated in the Resolution layer. */
final readonly class SlugQueryService implements SlugQueryServiceInterface
{
    public function __construct(private ResolutionService $resolution) {}

    public function checkAvailability(AvailabilityCriteria $criteria): SlugAvailabilityDTO
    {
        return $this->resolution->checkAvailability($criteria);
    }

    public function getCurrent(CurrentSlugCriteria $criteria): ?CurrentSlugDTO
    {
        return $this->resolution->getCurrent($criteria);
    }

    public function resolve(ResolutionCriteria $criteria): SlugResolutionDTO
    {
        return $this->resolution->resolve($criteria);
    }
}
